# Static Web Mercator tile server
Simplest possible Web Mercator tile server with Y-Coord flipping.

Takes in tiles built using `gdal2tiles` into a folder. Simply add .htaccess, tiler.php, a folder with the tileset into your webroot. To make the tileset discoverable add a metadata.json into the tileset folder. 
