<?php
use Riverline\MultiPartParser\StreamedPart;
function _scf_strrep1($needle, $replace, $haystack) {
    $pos = strpos($haystack, $needle);
    if ($pos === false) {
        return $haystack;
    }
    return substr_replace($haystack, $replace, $pos, strlen($needle));
}
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
    if(stristr($_SERVER['HTTP_CONTENT_TYPE'],'x-www-form-urlencoded')){
        parse_str($scFlight['event']['body'],$post);
    }
    $scFlight['post']=$post;
    $_POST=$post;
	//路径配置
    if(!Flight::get("scf_name"))Flight::set("scf_name",$scFlight['context']->function_name);
    $path=_scf_strrep1('/'.Flight::get("scf_name"),'',$scFlight['event']['path']);
	Flight::request()->__construct();
    Flight::request()->url=$path;
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
    $tmpHeaders["Content-Length"]=$response->getContentLength();
    $tmpHeaders['X-scf-path']=Flight::request()->url;
    $tmpHeaders['Content-Type']=isset($tmpHeaders['Content-Type'])?$tmpHeaders['Content-Type']:"text/html";
    $output=[
        'isBase64Encoded' => false,
        'statusCode' => $response->status,
        'headers' =>$tmpHeaders,
        'body' => $response->body
    ];
});