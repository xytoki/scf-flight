<?php
use Riverline\MultiPartParser\StreamedPart;
class xyTokiSCF{
	static $scFlight;
	static function strrep1($needle, $replace, $haystack) {
		$pos = strpos($haystack, $needle);
		if ($pos === false) {
			return $haystack;
		}
		return substr_replace($haystack, $replace, $pos, strlen($needle));
	}
	static function replaceClasses(){
		require dirname(__FILE__).'/Flight/Request.php';
		require dirname(__FILE__).'/Flight/Response.php';
	}
    static function clean(){
        Flight::request()->__construct();
        Flight::response()->clear();
        Flight::router()->reset();
	}
	static function parseHeaders(){
		$reqHeaders=array_merge(
			self::$scFlight['event']['headers'],
			self::$scFlight['event']['headerParameters']
		);
		foreach($reqHeaders as $a=>$b){
			$_SERVER['HTTP_'.str_replace("-","_",strtoupper($a))]=$b;
		}
		//ip method
		$_SERVER['REMOTE_ADDR']=self::$scFlight['event']["requestContext"]['sourceIp'];
		$_SERVER['REQUEST_METHOD']=strtoupper(self::$scFlight['event']['httpMethod']);
		$_SERVER['CONTENT_TYPE']=$_SERVER['HTTP_CONTENT_TYPE'];
	}
	static function parseGet(){
		global $_GET;
		$reqGet=array_merge(
			self::$scFlight['event']['queryString'],
			self::$scFlight['event']['queryStringParameters']
		);
		$_GET=$reqGet;
		self::$scFlight['get']=$reqGet;
	}
	static function parsePost(){
		global $_POST,$_FILES;
			$post=[];
			$file=[];
			if($_SERVER['REQUEST_METHOD']=="POST"){
				if(stristr($_SERVER['HTTP_CONTENT_TYPE'],'x-www-form-urlencoded')){
					//urlform
					parse_str(self::$scFlight['event']['body'],$post);
				}else if(stristr($_SERVER['HTTP_CONTENT_TYPE'],'ultipart/form-data')){
					//multipart
					$data = "Content-Type: ".$_SERVER['HTTP_CONTENT_TYPE']."\r\n\r\n";
					$data.=self::$scFlight['event']['body'];
					$stream = fopen('php://temp', 'rw');
					fwrite($stream, $data);
					rewind($stream);
					$document = new StreamedPart($stream);
					if ($document->isMultiPart()) {
						$parts = $document->getParts();
						foreach($parts as $a){
							if($a->isFile()) {
								$tmpFile=stream_get_meta_data(tmpfile())['uri'];
								file_put_contents($tmpFile,$a->getBody());
								$file[$a->getName()]=[
									"name"=>$a->getFileName(),
									"type"=>$a->getMimeType(),
									"size"=>strlen($a->getBody()),
									"tmp_name"=>$tmpFile
								];
							}else{
								$post[$a->getName()]=$a->getBody();
							}
						}
					}
				}
			}
			self::$scFlight['post']=$post;
			self::$scFlight['file']=$file;
			$_POST=$post;
			$_FILES=$file;
	}
	static function parseCookies(){

	}
	static function load(){
		self::replaceClasses();
		Flight::before("start",function(&$params, &$output){
            //全局清理
            self::clean();

			//保存全局变量
			self::$scFlight=[
				"event"=>json_decode(json_encode($params[0]),true),
				"context"=>$params[1]
			];
			self::parseHeaders();
			self::parseGet();
			self::parsePost();
			self::parseCookies();
			//路径配置
			if(!Flight::get("scf_name"))Flight::set("scf_name",self::$scFlight['context']->function_name);
			if(!Flight::get("scf_base"))Flight::set("scf_base",'/'.self::$scFlight['event']['requestContext']['stage'].'/'.self::$scFlight['context']->function_name);
			$path=self::strrep1('/'.Flight::get("scf_name"),'',self::$scFlight['event']['path']);
			Flight::request()->url=$path;
			Flight::request()->base=Flight::get("scf_base");
			if($params[2]){
				Flight::set("scf_static",$params[2]);
				Flight::route("*",function(){
					$file=Flight::get("scf_static").Flight::request()->url;
					if(!is_file($file))return true;
					$ext = pathinfo($file, PATHINFO_EXTENSION);
					$mimes = new \Mimey\MimeTypes;
					$filemime=$mimes->getMimeType($ext);
					if($filemime=="application/php"){
						echo "No input file specified.";
						return;
					}
					Flight::response()->header("Content-Type",$filemime);
					echo file_get_contents($file);
				});
			}
		});
		Flight::after("start",function(&$params, &$output){
			$response = Flight::response();
			$tmpHeaders=$response->headers;
			$response->write(ob_get_clean());
			ob_end_clean();
			ob_end_clean();
			ob_end_clean();
			$tmpHeaders["Content-Length"]=(string) $response->getContentLength();
			$tmpHeaders['X-scf-path']=Flight::request()->url;
			$tmpHeaders['Content-Type']=isset($tmpHeaders['Content-Type'])?$tmpHeaders['Content-Type']:"text/html;charset=utf-8";
			$output=[
				'isBase64Encoded' => false,
				'statusCode' => $response->status,
				'headers' =>$tmpHeaders,
				'body' => $response->body
			];
		});
	}
}
if(isset($_ENV['TENCENTCLOUD_RUNENV']))xyTokiSCF::load();