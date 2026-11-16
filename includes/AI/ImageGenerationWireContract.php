<?php
declare(strict_types=1);
namespace DigiForge\AI;
use DigiForge\Orders\Validator;
use DigiForge\POD\RasterArtworkCodec;

/** Versioned OpenAI Images GPT-image-1 wire subset; no network, authority or inferred print DPI. */
final class ImageGenerationWireContract
{
 public const VERSION='openai-images-gpt-image-1-v1';
 // Larger provider sizes exceed the existing 1,048,576-pixel local verifier.
 public const SUPPORTED_SIZES=['1024x1024'];
 public const SCHEMA_SHA256='6a6c681b182002de4ad335fa80214158f2d8f383a418f865c87876c3ed67cad9';
 public static function request(string $model,string $prompt,string $size,string $quality,?string $source):array {
  if($model!=='gpt-image-1'||!in_array($size,self::SUPPORTED_SIZES,true)||!in_array($quality,['low','medium','high'],true)||$prompt===''||strlen($prompt)>32000||preg_match('//u',$prompt)!==1||str_contains($prompt,"\0"))throw new \InvalidArgumentException('Unsupported reviewed image request.');
  $values=['model'=>$model,'prompt'=>$prompt,'n'=>1,'size'=>$size,'quality'=>$quality];$sourceHash=null;
  if($source===null){$body=Validator::canonicalJson($values+['output_format'=>'png','moderation'=>'auto']);$mime='application/json';$url='https://api.openai.com/v1/images/generations';}
  else {
   if(strlen($source)>4194304)throw new \InvalidArgumentException('Bounded source PNG required.');RasterArtworkCodec::decode($source);$sourceHash=hash('sha256',$source);
   $boundary='df-image-'.hash('sha256',Validator::canonicalJson($values).'|'.$sourceHash);if(str_contains($prompt,$boundary)||str_contains($source,$boundary))throw new \InvalidArgumentException('Multipart boundary collision.');$body='';
   foreach($values as $name=>$value)$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$name."\"\r\n\r\n".$value."\r\n";
   $body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"image\"; filename=\"source.png\"\r\nContent-Type: image/png\r\n\r\n".$source."\r\n--".$boundary."--\r\n";$mime='multipart/form-data; boundary='.$boundary;$url='https://api.openai.com/v1/images/edits';
  }
  $identity=['wire_version'=>self::VERSION,'url'=>$url,'content_type'=>$mime,'body_sha256'=>hash('sha256',$body),'source_sha256'=>$sourceHash];return $identity+['request_sha256'=>hash('sha256',Validator::canonicalJson($identity)),'model'=>$model,'body'=>$body];
 }
 public static function response(string $body,string $requestId,string $size,int $limit):array {
  if($limit<1||$limit>2097152||strlen($body)>4*(int)ceil($limit/3)+32768||!preg_match('/^req_[A-Za-z0-9_-]{1,180}$/D',$requestId)||!in_array($size,self::SUPPORTED_SIZES,true))throw new \InvalidArgumentException('Bounded exact image response identity required.');
  try{$r=json_decode($body,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \InvalidArgumentException('Malformed image response.');}
  if(!is_array($r)||!is_int($r['created']??null)||$r['created']<1||$r['created']>time()+300||!is_array($r['data']??null)||!array_is_list($r['data'])||count($r['data'])!==1||!is_array($r['data'][0])||isset($r['data'][0]['url'])||!is_string($r['data'][0]['b64_json']??null))throw new \InvalidArgumentException('Exactly one inline image required; URLs are never fetched.');
  $usage=self::usage($r['usage']??null);$encoded=$r['data'][0]['b64_json'];if(strlen($encoded)>4*(int)ceil($limit/3))throw new \InvalidArgumentException('Image size limit exceeded.');$png=base64_decode($encoded,true);
  if(!is_string($png)||strlen($png)>$limit||base64_encode($png)!==$encoded)throw new \InvalidArgumentException('Canonical image bytes required.');$image=RasterArtworkCodec::decode($png);[$w,$h]=array_map('intval',explode('x',$size));if($image['width_px']!==$w||$image['height_px']!==$h)throw new \InvalidArgumentException('Image geometry differs from exact request.');
  return ['request_id'=>$requestId,'provider_created'=>$r['created'],'provider_response_sha256'=>hash('sha256',$body),'usage'=>$usage,'png'=>$png,'image_sha256'=>hash('sha256',$png),'width_px'=>$w,'height_px'=>$h,'editable_vector'=>false,'print_dpi'=>null,'supplier_certified'=>false];
 }
 public static function usage(mixed $usage):array {
  if(!is_array($usage))throw new \InvalidArgumentException('Provider usage missing.');foreach(['input_tokens','output_tokens','total_tokens']as $key)if(!is_int($usage[$key]??null)||$usage[$key]<0||$usage[$key]>1000000000)throw new \InvalidArgumentException('Provider usage malformed.');
  if($usage['total_tokens']!==$usage['input_tokens']+$usage['output_tokens'])throw new \InvalidArgumentException('Provider totals differ.');$details=$usage['input_tokens_details']??null;
  if(!is_array($details)||!is_int($details['text_tokens']??null)||!is_int($details['image_tokens']??null)||$details['text_tokens']<0||$details['image_tokens']<0||$details['text_tokens']+$details['image_tokens']!==$usage['input_tokens'])throw new \InvalidArgumentException('Image/text token evidence differs.');
  return ['input_tokens'=>$usage['input_tokens'],'output_tokens'=>$usage['output_tokens'],'total_tokens'=>$usage['total_tokens'],'input_tokens_details'=>['text_tokens'=>$details['text_tokens'],'image_tokens'=>$details['image_tokens']]];
 }
}
