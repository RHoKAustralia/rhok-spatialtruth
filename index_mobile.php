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
		<link rel="stylesheet" href="css/bootstrap.min.css" />
		<link rel="stylesheet" href="css/bootstrap-responsive.min.css" />
		<script src="http://maps.google.com/maps/api/js?v=3&amp;sensor=false"></script>
		<script src="js/jquery-1.8.3.min.js" type="text/javascript"></script>
		<script src="js/OpenLayers-2.12/OpenLayers.js" type="text/javascript"></script>
		<script src="js/OpenLayers.Control.LoadingPanel.js" type="text/javascript"></script>
		<script type="text/javascript" src="js/bootstrap.min.js"></script>
		<style type="text/css">
			.main { padding-top: 40px; }
			#theNavbar { margin-bottom: 0px !important; }
            .olControlLayerSwitcher .olButton { color: white !important; }
            .olControlLayerSwitcher label.olButton { display: inline !important; }
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
            #viewer img { max-width:none; }
            @media (max-width: 767px) {
            	body { padding-left: 0 !important; padding-right: 0 !important; }
            }
		</style>
		<script type="text/javascript">

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

			var map = null;
			var myPositionLayer = null;
			var vicMapLayer = null;
			var mapAgentUrl = "http://localhost:8008/mapguide/mapagent/mapagent.fcgi"

			var metersPerUnit = 1.0;  //value returned from mapguide
	        OpenLayers.DOTS_PER_INCH = 96;

			function onResize() {
				$("#viewer").height($(window).height() - $("#theNavbar").height());
        		if (map != null)
        			map.updateSize();
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
						<?= $mapLL->GetX() ?>,
						<?= $mapLL->GetY() ?>,
						<?= $mapUR->GetX() ?>,
						<?= $mapUR->GetY() ?>
					)
				}
			}

			function setupMapGuideLayers(oMap) {
				vicMapLayer = new OpenLayers.Layer.MapGuide("Vicmap - WMS", mapAgentUrl, createMapGuideParams("Library://RHOK/Map/Satellite.MapDefinition", "Vicmap WMS"), createMapGuideOptions());
				oMap.addLayers([ vicMapLayer ]);
			}

			$(document).ready(function() {
				$(window).bind("resize", onResize);
				onResize();
				$("#viewer").width($("div.main").width());
				$("#viewer").height($("div.main").height());

				map = new OpenLayers.Map("viewer");
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
			    myPositionLayer = new OpenLayers.Layer.Vector("My Current Position");
			    map.addLayers([osm, gmap, gsat, ghyb, gphy]);
			    setupMapGuideLayers(map);
			    map.addLayers([myPositionLayer]);

			    map.addControl(new OpenLayers.Control.LoadingPanel());
			    map.addControl(new OpenLayers.Control.LayerSwitcher());

				navigator.geolocation.getCurrentPosition(function(pos) {
					setMyPosition(pos.coords.longitude, pos.coords.latitude);
				}, function(err) {
					map.zoomToMaxExtent();
				}); 
			});

			function setMyPosition(lon, lat) {
				myPositionLayer.removeAllFeatures();
				var txPt = new OpenLayers.LonLat(lon, lat).transform(
	                    new OpenLayers.Projection("EPSG:4326"),
	                    map.getProjectionObject()
	                );
				var geom = new OpenLayers.Geometry.Point(txPt.lon, txPt.lat);
				myPositionLayer.addFeatures([new OpenLayers.Feature.Vector(geom)])
				map.setCenter(
	                new OpenLayers.LonLat(lon, lat).transform(
	                    new OpenLayers.Projection("EPSG:4326"),
	                    map.getProjectionObject()
	                ), 16
	            );
			}

			function myLocation() {
				navigator.geolocation.getCurrentPosition(function(pos) {
					setMyPosition(pos.coords.longitude, pos.coords.latitude);
				}, function(err) {
					alert("Could not locate your current position");
				}); 
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
              <a class="brand" href="#">RHoK</a>
              <div class="nav-collapse">
                <ul class="nav">
                  <!--
                  <li><a href="javascript:viewer.invokeCommand('Layers')"><i class="icon-align-justify icon-white"></i> Layers</a></li>
                  -->
                  <li><a href="javascript:myLocation()"><i class="icon-screenshot icon-white"></i> My Location</a></li>
                </ul>
                <!--
                <ul class="nav pull-right">
                  <li class="dropdown">
                    <a href="#" class="dropdown-toggle" data-toggle="dropdown">Tools <b class="caret"></b></a>
                    <ul class="dropdown-menu">
                      <li><a href="javascript:viewer.invokeCommand('Buffer')">Buffer</a></li>
                      <li><a href="javascript:viewer.invokeCommand('Measure')">Measure</a></li>
                      <li><a href="javascript:viewer.invokeCommand('Redline')">Redline</a></li>
                      <li class="divider"></li>
                      <li class="nav-header">Draw</li>
                      <li><a href="javascript:viewer.invokeCommand('DrawFeature', 'POINT')">Point</a></li>
                      <li><a href="javascript:viewer.invokeCommand('DrawFeature', 'LINE')">Line</a></li>
                      <li><a href="javascript:viewer.invokeCommand('DrawFeature', 'POLYGON')">Polygon</a></li>
                    </ul>
                  </li>
                </ul>
                -->
                <!--
                <form class="navbar-search pull-right" action="">
                  <input type="text" class="search-query span2" placeholder="Search">
                </form>
                <ul class="nav pull-right">
                    <li class="dropdown">
                        <a href="#" class="dropdown-toggle" data-toggle="dropdown">Utility <b class="caret"></b></a>
                        <ul class="dropdown-menu">
                            <li><a href="#">Item 1</a></li>
                            <li><a href="#">Item 2</a></li>
                            <li><a href="#">Item 3</a></li>
                        </ul>
                    </li>
                </ul>
                -->
              </div><!-- /.nav-collapse -->
            </div><!-- /.container -->
          </div><!-- /navbar-inner -->
        </div><!-- /navbar -->
        <div id="viewer">
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