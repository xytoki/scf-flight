<?php
use Riverline\MultiPartParser\StreamedPart;
function _scf_patch(){
    $flight=dirname(dirname(dirname(dirname(__FILE__)))).'/mikecao/flight/flight/Flight.php';
    $patched=dirname(__FILE__).'/Flight/Flight.php';
	copy($patched,$flight);
}
_scf_patch();
function _scf_strrep1($needle, $replace, $haystack) {
    $pos = strpos($haystack, $needle);
    if ($pos === false) {
        return $haystack;
    }
    return substr_replace($haystack, $replace, $pos, strlen($needle));
}
function _scf_load(){
	Flight::before("start",function(&$params, &$output){
		//保存全局变量
		global $scFlight,$_GET,$_POST;
		$scFlight=[
			"event"=>json_decode(json_encode($params[0]),true),
			"context"=>$params[1]
		];
		
		//Header
		$reqHeaders=array_merge(
			$scFlight['event']['headers'],
			$scFlight['event']['headerParameters']
		);
		foreach($reqHeaders as $a=>$b){
			$_SERVER['HTTP_'.str_replace("-","_",strtoupper($a))]=$b;
		}
		
		//ip method
		$_SERVER['REMOTE_ADDR']=$scFlight['event']["requestContext"]['sourceIp'];
		$_SERVER['REQUEST_METHOD']=strtoupper($scFlight['event']['httpMethod']);
		$_SERVER['CONTENT_TYPE']=$_SERVER['HTTP_CONTENT_TYPE'];
		//GET
		$reqGet=array_merge(
			$scFlight['event']['queryString'],
			$scFlight['event']['queryStringParameters']
		);
		$_GET=$reqGet;
		$scFlight['get']=$reqGet;
		//post
		$post=[];
		$file=[];
		if($_SERVER['REQUEST_METHOD']=="POST"){
			if(stristr($_SERVER['HTTP_CONTENT_TYPE'],'x-www-form-urlencoded')){
				//urlform
				parse_str($scFlight['event']['body'],$post);
			}else if(stristr($_SERVER['HTTP_CONTENT_TYPE'],'ultipart/form-data')){
				//multipart
				$data = "Content-Type: ".$_SERVER['HTTP_CONTENT_TYPE']."\r\n\r\n";
				$data.=$scFlight['event']['body'];
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
		$scFlight['post']=$post;
		$scFlight['file']=$file;
		$_POST=$post;
		$_FILES=$file;
		//路径配置
		if(!Flight::get("scf_name"))Flight::set("scf_name",$scFlight['context']->function_name);
		if(!Flight::get("scf_base"))Flight::set("scf_base",'/'.$scFlight['event']['requestContext']['stage'].'/'.$scFlight['context']->function_name);
		$path=_scf_strrep1('/'.Flight::get("scf_name"),'',$scFlight['event']['path']);
		Flight::request()->__construct();
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
		global $scFlight;
		$response = Flight::response();
		if (!$response->sent()) {
			if ($code !== null) {
				$response->status($code);
			}
			$response->write(ob_get_clean());
			//$response->send();
		}
		$tmpHeaders=$response->headers;
		$tmpHeaders["Content-Length"]=(string) $response->getContentLength();
		$tmpHeaders['X-scf-path']=Flight::request()->url;
		$tmpHeaders['Content-Type']=isset($tmpHeaders['Content-Type'])?$tmpHeaders['Content-Type']:"text/html;charset=utf-8";
		$output=[
			'isBase64Encoded' => false,
			'statusCode' => $response->status,
			'headers' =>$tmpHeaders,
			'body' => $response->body
		];
		ob_end_clean();
		Flight::reset();
		_scf_load();
	});
}
_scf_load();