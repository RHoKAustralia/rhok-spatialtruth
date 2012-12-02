rhok-spatialtruth
=================

Spatial Truth project for RHoK

This project is a proof-of-concept mashup of the Vicmap API that makes use of the following software and services:

 * OpenLayers 2.12 (http://www.openlayers.org)
 * Twitter Bootstrap 2.2.1 (http://twitter.github.com/bootstrap)
 * bootstrap-notify (https://github.com/goodybag/bootstrap-notify)
 * MapGuide Open Source 2.4 (http://mapguide.osgeo.org)
 * Google Maps (http://maps.google.com)
 * Open Street Map (http://www.openstreetmap.org)
 * Vicmap WMS (http://116.240.195.134/vicmapapi/)
 * Country Fire Authority (CFA) RSS feeds (http://www.cfa.vic.gov.au/rss-feeds/)

NOTE: You must install the RHOK.mgp data package under \data via the MapGuide Site Administrator. This contains the Vicmap WMS data connections and the settings to re-project the data into a form that can be overlaid on top of Google/Bing/OSM

Installation
============

Install MapGuide Open Source 2.4 (choose bundled configuration)

git clone this repository under C:\Program Files\OSGeo\MapGuide\Web\www

Copy the RHOK.mgp data package to C:\Program Files\OSGeo\MapGuide\Server\Packages

Load this package using the MapGuide Site Administrator (http://localhost:8008/mapguide/mapadmin/login.php)

Launch the following URLs:

 * Map Comparison demo: http://localhost:8008/mapguide/[your git clone name]/mapComparing.html
 * Main demo: http://localhost:8008/mapguide/[your git clone name]/index_mobile.php