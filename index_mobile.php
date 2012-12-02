<?php

require_once("constants.php");

function CreateRuntimeMap($resourceSrvc, $sessionId, $mdfId)
{
    $map = new MgMap();
    $resId = new MgResourceIdentifier($mdfId);
    $mapName = $resId->GetName();
    $map->Create($resourceSrvc, $resId, $mapName);

    //create an empty selection object and store it in the session repository
    $sel = new MgSelection($map);
    $sel->Save($resourceSrvc, $mapName);

    //Save the runtime map blob
    $mapStateId = new MgResourceIdentifier("Session:" . $sessionId . "//" . $mapName . "." . MgResourceType::Map);
    $map->Save($resourceSrvc, $mapStateId);

    return $map;
}

MgInitializeWebTier(dirname(__FILE__)."/../webconfig.ini");

try
{
    $mapDefinition = "Library://RHOK/Map/SphericalMercator.MapDefinition";
    $siteConn = new MgSiteConnection();
    $userInfo = new MgUserInformation("Anonymous", "");
    $siteConn->Open($userInfo);
    $site1 = $siteConn->GetSite();
    $sessionId = $site1->CreateSession();
    $userInfo->SetMgSessionId($sessionId);
    $siteConn->Open($userInfo);

    $map = new MgMap($siteConn);

    //common resource service to be used by all scripts
    $resourceSrvc = $siteConn->CreateService(MgServiceType::ResourceService);

    $map = CreateRuntimeMap($resourceSrvc, $sessionId, $mapDefinition);

    $csFactory = new MgCoordinateSystemFactory();
    $origSRS = $map->GetMapSRS();
    $mapCs = $csFactory->Create($origSRS);
    $metersPerUnit = $mapCs->ConvertCoordinateSystemUnitsToMeters(1.0);

    $mapExtents = $map->GetMapExtent();
    $mapLL = $mapExtents->GetLowerLeftCoordinate();
    $mapUR = $mapExtents->GetUpperRightCoordinate();

    $layers = $map->GetLayers();
?>
<!doctype html>
<html>
    <head>
        <title>RHoK - Spatial Truth</title>
        <meta http-equiv="content-type" content="text/html; charset=utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
        <link rel="stylesheet" href="css/bootstrap.css" />
        <link rel="stylesheet" href="css/font-awesome.css" />
        <link rel="stylesheet" href="css/bootstrap-responsive.min.css" />
        <link rel="stylesheet" href="css/bootstrap-notify.css" />
        <link rel="stylesheet" href="css/styles/alert-bangtidy.css" />
        <link rel="stylesheet" href="css/styles/alert-blackgloss.css" />
        <script src="http://maps.google.com/maps/api/js?v=3&amp;sensor=false"></script>
        <script src="js/jquery-1.8.3.min.js" type="text/javascript"></script>
        <script src="js/OpenLayers-2.12/OpenLayers.js" type="text/javascript"></script>
        <script src="js/OpenLayers.Control.LoadingPanel.js" type="text/javascript"></script>
        <script type="text/javascript" src="js/bootstrap.min.js"></script>
        <script type="text/javascript" src="js/bootstrap-notify.js"></script>
        <style type="text/css">
            #viewer { padding-top: 43px; }
            #theNavbar { margin-bottom: 0px !important; }
            .olControlLoadingPanel {
                background-image:url(img/loading.gif);
                position: relative;
                right: 0;
                top: 0;
                width: 220px;
                height: 19px;
                background-position:center;
                background-repeat:no-repeat;
                display: none;
            }
            .notifications { z-index: 1000; }
            .bottom-right { bottom: 70px !important; }
            #viewer img { max-width:none; }
            @media (max-width: 767px) {
                body { padding-left: 0 !important; padding-right: 0 !important; }
            }
        </style>
        <script type="text/javascript">

            OpenLayers.ProxyHost = "xhrproxy.php?url=";

            var metersPerUnit = <?= $metersPerUnit ?>;  //value returned from mapguide
            var inPerUnit = OpenLayers.INCHES_PER_UNIT.m * metersPerUnit;
            OpenLayers.INCHES_PER_UNIT["dd"] = inPerUnit;
            OpenLayers.INCHES_PER_UNIT["degrees"] = inPerUnit;
            OpenLayers.DOTS_PER_INCH = 96;
            OpenLayers.Layer.MapGuide.prototype.SINGLE_TILE_PARAMS.format = "PNG8";

            var vicMapLayers = {
            <?php
            for ($i = 0; $i < $layers->GetCount(); $i++) {
                $layer = $layers->GetItem($i);
                echo "'".$layer->GetName(). "': { objid: '".$layer->GetObjectId()."', visible: ".($layer->GetVisible() ? "true" : "false")." }";
                if ($i < $layers->GetCount() - 1)
                    echo ",\n";
            }
            ?>
            };

            //Pre-defined list of spatially-enabled RSS feeds. This list is barren because providers are not
            //yet savvy enough to include cooridinates with their feed items
            var dataFeeds = {
                "CFA - Incidents": {
                    feedUrl: "http://osom.cfa.vic.gov.au/public/osom/IN_COMING.rss",
                    icon: new OpenLayers.Icon("img/fire.png", new OpenLayers.Size(24, 24))
                },
                "CFA - Warnings & Advice": {
                    feedUrl: "http://osom.cfa.vic.gov.au/public/osom/websites.rss",
                    icon: new OpenLayers.Icon("img/warning.png", new OpenLayers.Size(24, 24))
                }
            };

            var posPopup = null;
            var sessionId = '<?= $sessionId ?>';
            var map = null;
            var myPositionLayer = null;
            var vicMapLayer = null;
            var mapAgentUrl = "../mapagent/mapagent.fcgi";
            var gotoScale = 16;

            var metersPerUnit = 1.0;  //value returned from mapguide
            OpenLayers.DOTS_PER_INCH = 96;

            var MessageType =  {
                INFO: "info",
                SUCCESS: "success",
                WARNING: "warning",
                DANGER: "danger",
                INVERSE: "inverse",
                BLACKGLOSS: "blackgloss"
            }

            function notify(msgType, text) {
                var msg = {};
                switch (msgType)
                {
                    case MessageType.INFO:
                    {
                        msg.html = "<i class='icon-info-sign'></i> " + text;
                        break;
                    }
                    case MessageType.SUCCESS:
                    {
                        msg.html = "<i class='icon-ok'></i> " + text;
                        break;
                    }
                    case MessageType.WARNING:
                    {
                        msg.html = "<i class='icon-warning-sign'></i> " + text;
                        break;
                    }
                    case MessageType.DANGER:
                    {
                        msg.html = "<i class='icon-minus-sign'></i> " + text;
                        break;
                    }
                    default:
                    {
                        msg.text = text;
                    }
                }

                $(".notifications").notify({
                    type: msgType,
                    message: msg
                }).show();
            }

            function onResize() {
                $("#viewer").height($(window).height() - $("#theNavbar").height());
                if (map != null)
                    map.updateSize();
            }

            function mgKeepAlive() {
                $.ajax({
                    url: mapAgentUrl + "?OPERATION=GETSITEVERSION&VERSION=1.0.0&SESSION=" + sessionId,
                    method: 'get',
                    success: function(data, status, xhr) {
                        console.log("Kept alive");
                        setTimeout(mgKeepAlive, 20000);
                    },
                    failure: function(jqXHR, textStatus, errorThrown) {
                        console.error("Session expired");
                        window.location.refresh();
                    }
                });
            }

            function setVicMapLayerVisibility(layerName, bVisible) {
                if (typeof(vicMapLayers[layerName]) != 'undefined') {
                    vicMapLayers[layerName].visible = bVisible;
                    var showLayers = [];
                    var hideLayers = [];
                    for (var name in vicMapLayers) {
                        if (vicMapLayers[name].visible === true) {
                            showLayers.push(vicMapLayers[name].objid);
                        } else {
                            hideLayers.push(vicMapLayers[name].objid);
                        }
                    }

                    if (vicMapLayer.getVisibility()) {
                        var params = {
                            ts : (new Date()).getTime(),  //add a timestamp to prevent caching on the server
                            showLayers : showLayers.length > 0 ? showLayers.join(",") : null,
                            hideLayers : hideLayers.length > 0 ? hideLayers.join(",") : null
                        };
                        vicMapLayer.mergeNewParams(params);
                    }
                }
            }

            function createMapGuideParams(mdfId, groupName) {
                return {
                    session: '<?= $sessionId ?>',
                    mapName: '<?= $map->GetName() ?>',
                    //selectioncolor: "0xFF000000",
                    behavior: 2
                }
            }

            function createMapGuideOptions() {
                return {
                    isBaseLayer: false,
                    transitionEffect: 'resize',
                    buffer: 1,
                    useOverlay: true,
                    useAsyncOverlay: true,
                    singleTile: true,
                    units: "m",
                    maxExtent: new OpenLayers.Bounds(
                        15175240.12498,
                        -5058108.0785496,
                        17209076.573308,
                        -3893819.2638718
                    )
                }
            }

            function initialView() {
                map.zoomToExtent(new OpenLayers.Bounds(
                    15175240.12498,
                    -5058108.0785496,
                    17209076.573308,
                    -3893819.2638718
                ), true);
            }

            function setupMapGuideLayers(oMap) {
                vicMapLayer = new OpenLayers.Layer.MapGuide("Vicmap - WMS", mapAgentUrl, createMapGuideParams("Library://RHOK/Map/Satellite.MapDefinition", "Vicmap WMS"), createMapGuideOptions());
                oMap.addLayers([ vicMapLayer ]);
            }

            $(document).ready(function() {
                $("#addressSearch").hide();
                $(window).bind("resize", onResize);
                onResize();
                $("#viewer").width($("div.main").width());
                $("#viewer").height($("div.main").height());

                map = new OpenLayers.Map("viewer", {
                    restrictedExtent: new OpenLayers.Bounds(
                        15175240.12498,
                        -5058108.0785496,
                        17209076.573308,
                        -3893819.2638718
                    )
                });
                var osm = new OpenLayers.Layer.OSM();
                var gphy = new OpenLayers.Layer.Google(
                    "Google Physical",
                    {type: google.maps.MapTypeId.TERRAIN}
                );
                var gmap = new OpenLayers.Layer.Google(
                    "Google Streets", // the default
                    {numZoomLevels: 20}
                );
                var ghyb = new OpenLayers.Layer.Google(
                    "Google Hybrid",
                    {type: google.maps.MapTypeId.HYBRID, numZoomLevels: 20}
                );
                var gsat = new OpenLayers.Layer.Google(
                    "Google Satellite",
                    {type: google.maps.MapTypeId.SATELLITE, numZoomLevels: 22}
                );
                myPositionLayer = new OpenLayers.Layer.Vector("My Current Position", {
                    styleMap : new OpenLayers.StyleMap({
                        externalGraphic: "img/marker.png",
                        graphicOpacity: 1.0,
                        graphicWidth: 24,
                        graphicHeight: 24,
                    })
                });
                map.addLayers([osm, gmap, gsat, ghyb, gphy]);
                setupMapGuideLayers(map);
                map.addLayers([myPositionLayer]);

                map.addControl(new OpenLayers.Control.LoadingPanel());
                map.addControl(new OpenLayers.Control.MousePosition())
                map.zoomToMaxExtent();
                checkAddressSearchValidity();
                mgKeepAlive();
            });

            function isGoogleLayerActive() {
                return map.baseLayer.CLASS_NAME === "OpenLayers.Layer.Google";
            }

            function setMyPositionMarker(lon, lat) {
                myPositionLayer.removeAllFeatures();
                var txPt = new OpenLayers.LonLat(lon, lat).transform(
                        new OpenLayers.Projection("EPSG:4326"),
                        map.getProjectionObject()
                    );
                var geom = new OpenLayers.Geometry.Point(txPt.lon, txPt.lat);
                myPositionLayer.addFeatures([new OpenLayers.Feature.Vector(geom)]);
                var pos = new OpenLayers.LonLat(lon, lat).transform(
                    new OpenLayers.Projection("EPSG:4326"),
                    map.getProjectionObject()
                );
                map.setCenter(pos, gotoScale);
                return pos;
            }

            function setMarkerPopup(lon, lat, html) {
                if (posPopup != null) {
                    posPopup.destroy();
                    map.removePopup(posPopup);
                }
                posPopup = new OpenLayers.Popup.FramedCloud("Popup", 
                    new OpenLayers.LonLat(lon, lat), null,
                    html, null,
                    true // <-- true if we want a close (X) button, false otherwise
                );
                map.addPopup(posPopup);
            }

            function setMyPosition(lon, lat) {
                var pos = setMyPositionMarker(lon, lat);
                if (isGoogleLayerActive()) {
                    //NOTE: 2500 daily request limit for API key-less operations
                    //and only can be used in conjunction with a Google map being displayed
                    var url = OpenLayers.ProxyHost + encodeURIComponent("http://maps.googleapis.com/maps/api/geocode/json?latlng=" + lat + "," + lon + "&sensor=true");
                    $.getJSON(url, function(data, status, xhr) {
                        if (data.status === "OK") {
                            if (data.results.length > 0) {
                                setMarkerPopup(pos.lon, pos.lat, "<strong>Address:</strong> " + data.results[0].formatted_address)
                            }
                        } else {
                            alert("Geocode request failed: " + data.status)
                        }
                    });
                }
            }

            function populateBaseLayers() {
                var basehtml = "";
                var overhtml = "";
                for (var i = 0; i < map.layers.length; i++) {
                    var layer = map.layers[i];
                    if (layer.isBaseLayer) {
                        if (layer.getVisibility()) {
                            basehtml += "<div class='controls'><label class='radio'><input type='radio' name='baselayername' checked='checked' value='" + layer.name + "' /><i class='icon-reorder'></i> " + layer.name + "</label></div>";
                        } else {
                            basehtml += "<div class='controls'><label class='radio'><input type='radio' name='baselayername' value='" + layer.name + "' /><i class='icon-reorder'></i> " + layer.name + "</label></div>";
                        }
                    } else {
                        if (layer == myPositionLayer) //Skip this layer
                            continue;
                        if (layer == vicMapLayer) { //You're treated specially
                            for (var vlName in vicMapLayers) {
                                var vlLayer = vicMapLayers[vlName];
                                if (vlLayer.visible) {
                                    overhtml += "<div class='controls'><label class='checkbox'><input data-vicmap-layer-name='" + vlName + "' type='checkbox' checked='checked' value='" + vlName + "' /><i class='icon-reorder'></i> " + vlName + "</label></div>";
                                } else {
                                    overhtml += "<div class='controls'><label class='checkbox'><input data-vicmap-layer-name='" + vlName + "' type='checkbox' value='" + vlName + "' /><i class='icon-reorder'></i> " + vlName + "</label></div>";
                                }
                            }
                        } else {
                            if (layer.getVisibility()) {
                                overhtml += "<div class='controls'><label class='checkbox'><input type='checkbox' checked='checked' value='" + layer.name + "' /><i class='icon-reorder'></i> " + layer.name + "</label></div>";
                            } else {
                                overhtml += "<div class='controls'><label class='checkbox'><input type='checkbox' value='" + layer.name + "' /><i class='icon-reorder'></i> " + layer.name + "</label></div>";
                            }
                        }
                    }
                }
                $("#baseLayerList").html(basehtml + overhtml);
            }

            function populateDataFeeds() {
                var html = "";
                for(var name in dataFeeds) {
                    html += "<div class='controls'><label class='checkbox'><input type='checkbox' value='" + name + "' /><i class='icon-rss'></i> " + name + "</label></div>";
                }
                $("#dataFeedList").html(html);
            }

            function findLayerByName(layerName) {
                for (var i = 0; i < map.layers.length; i++) {
                    if (map.layers[i].name === layerName) {
                        return map.layers[i];
                    }
                }
                return null;
            }

            function checkAddressSearchValidity() {
                if (isGoogleLayerActive())
                    $("#addressSearch").show();
                else
                    $("#addressSearch").hide();
            }

            function applyLayerVisibility() {
                notify(MessageType.INFO, "Applying layer visibility");
                var showVicMapLayers = [];
                var hideVicMapLayers = [];
                $("input", "#baseLayerList").each(function(i, e) {
                    var el = $(e);
                    if (typeof(el.attr("data-vicmap-layer-name")) != 'undefined') {
                        var oldVal = vicMapLayers[el.val()].visible;
                        if (oldVal != el.is(":checked")) {
                            vicMapLayers[el.val()].visible = el.is(":checked");
                            if (el.is(":checked"))
                                showVicMapLayers.push(vicMapLayers[el.val()].objid);
                            else
                                hideVicMapLayers.push(vicMapLayers[el.val()].objid)
                        }
                    } else {
                        var layer = findLayerByName(el.val());
                        if (layer != null)
                            layer.setVisibility(el.is(":checked"));

                        if (layer.getVisibility() && layer.isBaseLayer)
                            map.setBaseLayer(layer);
                    }
                });
                if (showVicMapLayers.length > 0 || hideVicMapLayers.length > 0) {
                    var params = {
                        ts : (new Date()).getTime(),  //add a timestamp to prevent caching on the server
                        showLayers : showVicMapLayers.length > 0 ? showVicMapLayers.join(",") : null,
                        hideLayers : hideVicMapLayers.length > 0 ? hideVicMapLayers.join(",") : null
                    };
                    vicMapLayer.mergeNewParams(params);
                }
                checkAddressSearchValidity();
                notify(MessageType.SUCCESS, "Layer visibility changed");
                $("#viewer").focus();
            }

            function applyDataFeeds() {
                notify(MessageType.INFO, "Applying data feeds");
                var removeLayers = [];
                for (var i = 0; i < map.layers.length; i++) {
                    if (map.layers[i].CLASS_NAME === "OpenLayers.Layer.GeoRSS") {
                        removeLayers.push(map.layers[i]);
                    }
                }
                for (var i = 0; i < removeLayers.length; i++) {
                    map.removeLayer(removeLayers[i]);
                }
                var layers = [];
                $("input:checked", "#dataFeedList").each(function(i, e) {
                    var name = $(e).val();
                    var feed = dataFeeds[name];
                    layers.push(
                        (typeof(feed.icon) != 'undefined') ?
                            new OpenLayers.Layer.GeoRSS(name, feed.feedUrl, { icon: feed.icon }) :
                            new OpenLayers.Layer.GeoRSS(name, feed.feedUrl)
                    );
                });
                if (layers.length > 0) {
                    map.addLayers(layers);
                    var bounds = new OpenLayers.Bounds();
                    for (var i = 0; i < layers.length; i++) {
                        var lyr = layers[i];
                        var lyrBounds = lyr.getExtent();
                        if (lyrBounds != null)
                            bounds.extend(lyrBounds);
                    }
                    map.zoomToExtent(bounds, true);
                    notify(MessageType.SUCCESS, "Data Feeds Applied");
                }
                $("#viewer").focus();
            }

            function performAddressQuery() {
                if (isGoogleLayerActive()) {
                    var addr = $("#qryAddress").val();
                    notify(MessageType.INFO, "Performing Address Query");
                    //NOTE: 2500 daily request limit for API key-less operations
                    //and only can be used in conjunction with a Google map being displayed
                    var url = OpenLayers.ProxyHost + encodeURIComponent("http://maps.googleapis.com/maps/api/geocode/json?address=" + addr + "&sensor=true");
                    $.getJSON(url, function(data, status, xhr) {
                        if (data.status === "OK") {
                            if (data.results.length > 0) {
                                var res = data.results[0];
                                if (typeof(res.geometry) != 'undefined') {
                                    notify(MessageType.INFO, "Going to first result");
                                    var lonlat = new OpenLayers.LonLat(
                                        res.geometry.location.lng,
                                        res.geometry.location.lat
                                    ).transform(new OpenLayers.Projection("EPSG:4326"), map.getProjectionObject());
                                    setMyPositionMarker(res.geometry.location.lng, res.geometry.location.lat);
                                    map.setCenter(lonlat, gotoScale);
                                }
                            } else {
                                notify(MessageType.DANGER, "Specifed address query returned no results");
                            }
                        } else if (data.status == "ZERO_RESULTS") {
                            notify(MessageType.DANGER, "Specifed address query returned no results");
                        } else {
                            notify(MessageType.DANGER, "Google Geocoding API Error: " + data.status);
                        }
                    });
                } else {
                    alert("Can only search addresses with a Google layer visibile. Sorry, Terms of Service requires it");
                }
                $("#viewer").focus();
                return false;
            }

            function myLocation() {
                navigator.geolocation.getCurrentPosition(function(pos) {
                    setMyPosition(pos.coords.longitude, pos.coords.latitude);
                    notify(MessageType.SUCCESS, "Zoomed to your location");
                }, function(err) {
                    notify(MessageType.DANGER, "Could not locate your current position");
                }, { timeout: 5000 }); //default timeout is infinite! Real smart!
            }

        </script>
    </head>
    <body>
        <div id="theNavbar" class="navbar navbar-inverse">
          <div class="navbar-inner" style="-webkit-border-radius: 0; -moz-border-radius: 0; border-radius: 0;">
            <div class="container">
              <a class="btn btn-navbar" data-toggle="collapse" data-target=".nav-collapse">
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
              </a>
              <a class="brand" href="#">RHoK - Spatial Truth</a>
              <div class="nav-collapse">
                <ul class="nav">
                  <li><a href="javascript:myLocation()"><i class="icon-screenshot"></i> My Location</a></li>
                  <li><a href="javascript:initialView()"><i class="icon-check-empty"></i> Initial View</a></li>
                  <li><a href="#baseLayerModal" data-toggle="modal" onclick="populateBaseLayers()"><i class="icon-align-justify"></i> Layers</a></li>
                  <li><a href="#dataFeedsModal" data-toggle="modal" onclick="populateDataFeeds()"><i class="icon-rss"></i> Data Feeds</a></li>
                </ul>
                <form id="addressSearch" class="navbar-search pull-right" onsubmit="return performAddressQuery()">
                  <input type="text" id="qryAddress" class="search-query" placeholder="Address Search">
                </form>
              </div><!-- /.nav-collapse -->
            </div><!-- /.container -->
          </div><!-- /navbar-inner -->
        </div><!-- /navbar -->
        <div id="viewer">
        </div>
        <div class='notifications bottom-right'></div>
        <!-- Base Layers -->
        <div id="baseLayerModal" class="modal hide" tabindex="-1" role="dialog" aria-labelledby="baseLayerModalLabel" aria-hidden="true">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
            <h3 id="baseLayerModalLabel"><i class="icon-align-justify"></i> Base Layers</h3>
          </div>
          <div class="modal-body">
            <p>Choose the layers you want to see on this map</p>
            <div class="control-group" id="baseLayerList">
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn" data-dismiss="modal" aria-hidden="true" onclick="applyLayerVisibility()"><i class="icon-ok"></i> Apply</button>
          </div>
        </div>
        <!-- Data Feeds -->
        <div id="dataFeedsModal" class="modal hide" tabindex="-1" role="dialog" aria-labelledby="dataFeedsModalLabel" aria-hidden="true">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
            <h3 id="dataFeedsModalLabel"><i class="icon-rss"></i> Data Feeds</h3>
          </div>
          <div class="modal-body">
            <p>Tick the data feeds to show on this map</p>
            <div class="control-group" id="dataFeedList">
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn" data-dismiss="modal" aria-hidden="true" onclick="applyDataFeeds()"><i class="icon-ok"></i> Apply</button>
          </div>
        </div>
    </body>
</html>
<?php
}
catch (MgException $e)
{
    $phpErrorMessage = $e->getMessage();
    $phpStackTrace = $e->getTraceAsString();
    $initializationErrorMessage = $e->GetExceptionMessage();
    $initializationErrorDetail = $e->GetDetails();
    $initializationErrorStackTrace = $e->GetStackTrace();
    echo "<p>An error occurred during initialization</p>";
    echo "<strong>PHP Exception Information</strong>";
    echo "<p>Message: $phpErrorMessage</p>";
    echo "<p>Stack Trace: <pre>$phpStackTrace</pre></p>";
    echo "<strong>MapGuide Exception Information</strong>";
    echo "<p>Message: $initializationErrorMessage</p>";
    echo "<p>Detail: $initializationErrorDetail</p>";
    echo "<p>Stack Trace: <pre>$initializationErrorStackTrace</pre></p>";
}
catch (Exception $e)
{
    $phpErrorMessage = $e->getMessage();
    $phpStackTrace = $e->getTraceAsString();
    echo "<p>An error occurred during initialization</p>";
    echo "<strong>PHP Exception Information</strong>";
    echo "<p>Message: $phpErrorMessage</p>";
    echo "<p>Stack Trace: <pre>$phpStackTrace</pre></p>";
}
?>