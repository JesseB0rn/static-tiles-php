<?php 

global $config;
$config['serverTitle'] = 'Jesses tile server';
$config['availableFormats'] = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pbf', 'hybrid'];
$config['dataRoot'] = '';


Router::serve([
    '/services' => 'Json:getInfo',
    '/services/:alpha.json' => 'Json:getJson',
    'services/:alpha/:number/:number/:alpha' => 'Tiles:getTile',
]);


class Server {

  /**
   * Configuration of TileServer [baseUrls, serverTitle]
   * @var array
   */
  public $config;

  /**
   * Datasets stored in file structure
   * @var array
   */
  public $fileLayer = [];

  /**
   * Datasets stored in database
   * @var array
   */
  public $dbLayer = [];

  /**
   * PDO database connection
   * @var object
   */
  public $db;

  /**
   * Set config
   */
  public function __construct() {
    $this->config = $GLOBALS['config'];

    if($this->config['dataRoot'] != ''
       && substr($this->config['dataRoot'], -1) != '/' ){
      $this->config['dataRoot'] .= '/';
    }

    //Get config from enviroment
    $envServerTitle = getenv('serverTitle');
    if($envServerTitle !== false){
      $this->config['serverTitle'] = $envServerTitle;
    }
    $envBaseUrls = getenv('baseUrls');
    if($envBaseUrls !== false){
      $this->config['baseUrls'] = is_array($envBaseUrls) ?
              $envBaseUrls : explode(',', $envBaseUrls);
    }
    $envTemplate = getenv('template');
    if($envBaseUrls !== false){
      $this->config['template'] = $envTemplate;
    }
  }

  /**
   * Looks for datasets
   */
  public function setDatasets() {
    $mjs = glob($this->config['dataRoot'] . '*/metadata.json');
    $mbts = glob($this->config['dataRoot'] . '*.mbtiles');
    if ($mjs) {
      foreach (array_filter($mjs, 'is_readable') as $mj) {
        $layer = $this->metadataFromMetadataJson($mj);
        array_push($this->fileLayer, $layer);
      }
    }
  }

  /**
   * Processing params from router <server>/<layer>/<z>/<x>/<y>.ext
   * @param array $params
   */
  public function setParams($params) {
    if (isset($params[1])) {
      $this->layer = $params[1];
    }
    $params = array_reverse($params);
    if (isset($params[2])) {
      $this->z = $params[2];
      $this->x = $params[1];
      $file = explode('.', $params[0]);
      $this->y = $file[0];
      $this->ext = isset($file[1]) ? $file[1] : null;
    }
  }

  /**
   * Get variable don't independent on sensitivity
   * @param string $key
   * @return boolean
   */
  public function getGlobal($isKey) {
    $get = $_GET;
    foreach ($get as $key => $value) {
      if (strtolower($isKey) == strtolower($key)) {
        return $value;
      }
    }
    return false;
  }


  /**
   * Testing if is a file layer
   * @param string $layer
   * @return boolean
   */
  public function isFileLayer($layer) {
    if (is_dir($layer)) {
      return true;
    } else {
      return false;
    }
  }

  public function metadataFromMetadataJson($jsonFileName) {
    $metadata = json_decode(file_get_contents($jsonFileName), true);
    $metadata['basename'] = str_replace('/metadata.json', '', $jsonFileName);
    return $this->metadataValidation($metadata);
  }

  /**
   * Valids metaJSON
   * @param object $metadata
   * @return object
   */
  public function metadataValidation($metadata) {
    if (!array_key_exists('bounds', $metadata)) {
      $metadata['bounds'] = [-180, -85.06, 180, 85.06];
    } elseif (!is_array($metadata['bounds'])) {
      $metadata['bounds'] = array_map('floatval', explode(',', $metadata['bounds']));
    }
    // if (!array_key_exists('profile', $metadata)) {
    //   $metadata['profile'] = 'mercator';
    // }
    if (array_key_exists('minzoom', $metadata)){
      $metadata['minzoom'] = intval($metadata['minzoom']);
    }else{
      $metadata['minzoom'] = 0;
    }
    if (array_key_exists('maxzoom', $metadata)){
      $metadata['maxzoom'] = intval($metadata['maxzoom']);
    }else{
      $metadata['maxzoom'] = 18;
    }
    if (!array_key_exists('format', $metadata)) {
      if(array_key_exists('tiles', $metadata)){
        $pos = strrpos($metadata['tiles'][0], '.');
        $metadata['format'] = trim(substr($metadata['tiles'][0], $pos + 1));
      }
    }
    $formats = $this->config['availableFormats'];
    if(!in_array(strtolower($metadata['format']), $formats)){
        $metadata['format'] = 'png';
    }
    if (!array_key_exists('scale', $metadata)) {
      $metadata['scale'] = 1;
    }
    if(!array_key_exists('tiles', $metadata)){
      $tiles = [];
      foreach ($this->config['baseUrls'] as $url) {
        $url = '' . $this->config['protocol'] . '://' . $url . '/services/' .
                $metadata['basename'] . '/{z}/{x}/{y}';
        if(strlen($metadata['format']) <= 4){
          $url .= '.' . $metadata['format'];
        }
        $tiles[] = $url;
      }
      $metadata['tiles'] = $tiles;
    }
    return $metadata;
  }


  /**
   * Returns tile of dataset
   * @param string $tileset
   * @param integer $z
   * @param integer $y
   * @param integer $x
   * @param string $ext
   */
  public function renderTile($tileset, $z, $y, $x, $ext) {
    if ($this->isFileLayer($tileset)) {

      $z = floatval($z);
      $y = floatval($y);
      $x = floatval($x);
      $flip = true;
      if ($flip) {
        $y = pow(2, $z) - 1 - $y;
      }


      $name = './' . $tileset . '/' . $z . '/' . $x . '/' . $y;
      $mime = 'image/';
      if($ext != null){
        $name .= '.' . $ext;
      }
      if ($fp = @fopen($name, 'rb')) {
        if($ext != null){
          $mime .= $ext;
        }else{
          //detect image type from file
          $mimetypes = ['gif', 'jpeg', 'png'];
          $mime .= $mimetypes[exif_imagetype($name) - 1];
        }
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($name));
        fpassthru($fp);
        die;
      } else {
        //scale of tile (for retina tiles)
        $meta = json_decode(file_get_contents($tileset . '/metadata.json'));
        if(!isset($meta->scale)){
          $meta->scale = 1;
        }
      }
      http_response_code(404);
      echo 'Server: Unknown or not specified tile "' . $x . $y . '"';
      
    } else {
      http_response_code(404);
      echo 'Server: Unknown or not specified dataset "' . $tileset . '"';
      die;
    }
  }

  public function notFound() {
    http_response_code(404);
    echo "404 Not Found";
    // echo "tiler v0.0 by jb0";
  }
}

/**
 * JSON service
 */
class Json extends Server {

  /**
   * Callback for JSONP default grid
   * @var string
   */
  private $callback = 'grid';

  /**
   * @param array $params
   */
  public $layer = 'index';

  /**
   * @var integer
   */
  public $z;

  /**
   * @var integer
   */
  public $y;

  /**
   * @var integer
   */
  public $x;

  /**
   * @var string
   */
  public $ext;

  /**
   *
   * @param array $params
   */
  public function __construct($params) {
    parent::__construct();
    parent::setParams($params);
    if (isset($_GET['callback']) && !empty($_GET['callback'])) {
      $this->callback = $_GET['callback'];
    }
  }

  /**
   * Adds metadata about layer
   * @param array $metadata
   * @return array
   */
  public function metadataTileJson($metadata) {
    $metadata['tilejson'] = '3.0.0';
    $metadata['scheme'] = 'xyz';
    if (array_key_exists('json', $metadata)) {
      $mjson = json_decode(stripslashes($metadata['json']));
      foreach ($mjson as $key => $value) {
        if ($key != 'Layer'){
          $metadata[$key] = $value;
        }
      }
      unset($metadata['json']);
    }
    return $metadata;
  }

  /**
   * Creates JSON from array
   * @param string $basename
   * @return string
   */
  private function createJson($basename) {
    $maps = array_merge($this->fileLayer, []);
    if ($basename == 'index') {
      $output = '[';
      foreach ($maps as $map) {
        $output = $output . json_encode($this->metadataTileJson($map)) . ',';
      }
      if (strlen($output) > 1) {
        $output = substr_replace($output, ']', -1);
      } else {
        $output = $output . ']';
      }
    } else {
      foreach ($maps as $map) {
        if (strpos($map['basename'], $basename) !== false) {
          $output = json_encode($this->metadataTileJson($map));
          break;
        }
      }
    }
    if (!isset($output)) {
      echo 'TileServer: unknown map ' . $basename;
      die;
    }
    return stripslashes($output);
  }

  /**
   * Returns JSON with callback
   */
  public function getJson() {
    parent::setDatasets();
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json; charset=utf-8');
    if ($this->callback !== 'grid') {
      echo $this->callback . '(' . $this->createJson($this->layer) . ');'; die;
    } else {
      echo $this->createJson($this->layer); die;
    }
  }

    /**
   * Returns server info
   */
  public function getInfo() {
    parent::setDatasets();
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json; charset=utf-8');
    echo $this->createJson("index");
  }
}


class Tiles extends Server {
  /**
   * @param array $params
   */
  public $layer;

  /**
   * @var integer
   */
  public $z;

  /**
   * @var integer
   */
  public $y;

  /**
   * @var integer
   */
  public $x;

  /**
   * @var string
   */
  public $ext;
    /**
   *
   * @param array $params
   */
  public function __construct($params) {
    parent::__construct();
    if (isset($params)) {
      parent::setParams($params);
    }
  }

  public function getTile() {
      parent::renderTile($this->layer, $this->z, $this->y, $this->x, $this->ext);
  }

}


/**
 * Simple router
 */
class Router {

  /**
   * @param array $routes
   */
  public static function serve($routes) {
    $path_info = '/';
	global $config;
	$xForwarded = false;
	if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
		if ($_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
			$xForwarded = true;
		}
	}
	$config['protocol'] = ((isset($_SERVER['HTTPS']) or (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) or $xForwarded) ? 'https' : 'http';
    if (!empty($_SERVER['PATH_INFO'])) {
      $path_info = $_SERVER['PATH_INFO'];
    } else if (!empty($_SERVER['ORIG_PATH_INFO']) && strpos($_SERVER['ORIG_PATH_INFO'], 'tileserver.php') === false) {
      $path_info = $_SERVER['ORIG_PATH_INFO'];
    } else if (!empty($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/tileserver.php') !== false) {
      $path_info = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
      $config['baseUrls'][0] = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . '?';
    } else {
      if (!empty($_SERVER['REQUEST_URI'])) {
        $path_info = (strpos($_SERVER['REQUEST_URI'], '?') > 0) ? strstr($_SERVER['REQUEST_URI'], '?', true) : $_SERVER['REQUEST_URI'];
      }
    }
    $discovered_handler = null;
    $regex_matches = [];

    if ($routes) {
      $tokens = [
          ':string' => '([a-zA-Z]+)',
          ':number' => '([0-9]+)',
          ':alpha' => '([a-zA-Z0-9-_@\.]+)'
      ];
      //global $config;
      foreach ($routes as $pattern => $handler_name) {
        $pattern = strtr($pattern, $tokens);
        if (preg_match('#/?' . $pattern . '/?$#', $path_info, $matches)) {
          if (!isset($config['baseUrls'])) {
            $config['baseUrls'][0] = $_SERVER['HTTP_HOST'] . preg_replace('#/?' . $pattern . '/?$#', '', $path_info);
          }
          $discovered_handler = $handler_name;
          $regex_matches = $matches;
          break;
        }
      }
    }
    $handler_instance = null;
    if ($discovered_handler) {
      if (is_string($discovered_handler)) {
        if (strpos($discovered_handler, ':') !== false) {
          $discoverered_class = explode(':', $discovered_handler);
          $discoverered_method = explode(':', $discovered_handler);
          $handler_instance = new $discoverered_class[0]($regex_matches);
          call_user_func([$handler_instance, $discoverered_method[1]]);
        } else {
          $handler_instance = new $discovered_handler($regex_matches);
        }
      } elseif (is_callable($discovered_handler)) {
        $handler_instance = $discovered_handler();
      }
    } else {
      if (!isset($config['baseUrls'][0])) {
        $config['baseUrls'][0] = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
      }
      if (strpos($_SERVER['REQUEST_URI'], '=') != false) {
        $kvp = explode('=', $_SERVER['REQUEST_URI']);
        $_GET['callback'] = $kvp[1];
        $params[0] = 'index';
        $handler_instance = new Json($params);
        $handler_instance->getJson();
      }
      $handler_instance = new Server;
      $handler_instance->notFound();
    }
  }

}

?>