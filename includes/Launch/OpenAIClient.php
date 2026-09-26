<?php

declare(strict_types=1);

namespace DigiForge\Launch;

use DigiForge\Database\Tables;
use DigiForge\Integrations\CredentialVault;
use DigiForge\Integrations\Repository as IntegrationRepository;
use DigiForge\Security\Logger;

final class OpenAIClient
{
    private const RESPONSES_URL = 'https://api.openai.com/v1/responses';
    private const DEFAULT_MODEL = 'gpt-5.6-luna';

    public function research(string $brief): array|\WP_Error { return $this->request($brief, true, 4000); }

    /** Exactly one minimal provider request; no web search, persistence, retry, or downstream workflow. */
    public function controlledConnectivityTest(): array|\WP_Error
    {
        $connector=$this->connector(); if(is_wp_error($connector))return $connector;
        $apiKey=$this->secret((int)$connector['id'],'api_key'); if(is_wp_error($apiKey))return $apiKey;
        $body=['model'=>self::DEFAULT_MODEL,'input'=>'Return exactly this JSON object: {"digiforge_controlled_test":"PASS"}','max_output_tokens'=>100,'text'=>['format'=>['type'=>'json_object']]];
        $response=wp_remote_post(self::RESPONSES_URL,['timeout'=>30,'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,'headers'=>['Authorization'=>'Bearer '.$apiKey,'Content-Type'=>'application/json'],'body'=>wp_json_encode($body)]); unset($apiKey);
        if(is_wp_error($response)){Logger::audit('controlled_ai_test_failed',['reason'=>'transport'],'system','controlled_ai_test');return new \WP_Error('digiforge_controlled_ai_transport',__('Controlled AI provider test failed.','digiforge'),['status'=>502]);}
        $status=(int)wp_remote_retrieve_response_code($response);$decoded=json_decode((string)wp_remote_retrieve_body($response),true);$responseId=is_array($decoded)?sanitize_text_field((string)($decoded['id']??'')):'';
        if($status<200||$status>=300||!is_array($decoded)){Logger::audit('controlled_ai_test_failed',['http_status'=>$status,'request_id'=>$this->requestId($response)],'system','controlled_ai_test');return new \WP_Error('digiforge_controlled_ai_provider',__('AI provider rejected the controlled test. No retry was attempted.','digiforge'),['status'=>502]);}
        $payload=$this->decodeJsonObject($this->extractOutputText($decoded));$passed=is_array($payload)&&($payload['digiforge_controlled_test']??null)==='PASS';
        Logger::audit($passed?'controlled_ai_test_passed':'controlled_ai_test_failed',['response_id'=>$responseId,'http_status'=>$status,'downstream_actions_performed'=>false,'automatic_retry_performed'=>false],'system','controlled_ai_test');
        return $passed?['status'=>'PASS','response_id'=>$responseId,'model'=>sanitize_text_field((string)($decoded['model']??self::DEFAULT_MODEL)),'downstream_actions_performed'=>false,'automatic_retry_performed'=>false]:new \WP_Error('digiforge_controlled_ai_contract',__('AI provider responded but did not satisfy the controlled-test contract. No retry was attempted.','digiforge'),['status'=>502]);
    }
    public function develop(string $brief): array|\WP_Error
    {
        // Product Factory manifests contain complete customer + marketing assets and
        // therefore need the full structured-output allowance. Keep ordinary product
        // development prompts at the smaller budget.
        $productionManifest = str_contains($brief, 'production-ready DigiForge asset manifest');
        return $this->request($brief, false, $productionManifest ? 16000 : 8000);
    }

    /** Start a long structured generation without holding a PHP worker open. */
    public function startBackgroundDevelop(string $brief, int $maxOutputTokens = 16000): array|\WP_Error
    {
        $connector=$this->connector(); if(is_wp_error($connector))return $connector;
        $apiKey=$this->secret((int)$connector['id'],'api_key'); if(is_wp_error($apiKey))return $apiKey;
        $body=['model'=>self::DEFAULT_MODEL,'input'=>$brief,'max_output_tokens'=>max(1000,min(16000,$maxOutputTokens)),'text'=>['format'=>['type'=>'json_object']],'background'=>true,'store'=>true];
        $response=wp_remote_post(self::RESPONSES_URL,['timeout'=>30,'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,'headers'=>['Authorization'=>'Bearer '.$apiKey,'Content-Type'=>'application/json'],'body'=>wp_json_encode($body)]); unset($apiKey);
        if(is_wp_error($response))return new \WP_Error('digiforge_launch_ai_transport',__('AI background request failed.','digiforge'),['status'=>502]);
        $status=(int)wp_remote_retrieve_response_code($response);$decoded=json_decode((string)wp_remote_retrieve_body($response),true);
        if($status<200||$status>=300||!is_array($decoded))return new \WP_Error('digiforge_launch_ai_provider',__('AI provider rejected the background request.','digiforge'),['status'=>502]);
        $id=sanitize_text_field((string)($decoded['id']??'')); if($id==='')return new \WP_Error('digiforge_launch_ai_empty',__('AI provider did not return a response id.','digiforge'),['status'=>502]);
        return ['response_id'=>$id,'status'=>sanitize_key((string)($decoded['status']??'queued'))];
    }

    /** Poll a background response; completed responses are decoded with the same strict JSON contract. */
    public function retrieveBackground(string $responseId): array|\WP_Error
    {
        $responseId=sanitize_text_field($responseId); if(!preg_match('/^resp_[A-Za-z0-9_-]+$/',$responseId))return new \WP_Error('digiforge_launch_ai_response_id',__('AI response id is invalid.','digiforge'),['status'=>400]);
        $connector=$this->connector(); if(is_wp_error($connector))return $connector;
        $apiKey=$this->secret((int)$connector['id'],'api_key'); if(is_wp_error($apiKey))return $apiKey;
        $response=wp_remote_get(self::RESPONSES_URL.'/'.rawurlencode($responseId),['timeout'=>30,'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,'headers'=>['Authorization'=>'Bearer '.$apiKey]]); unset($apiKey);
        if(is_wp_error($response))return new \WP_Error('digiforge_launch_ai_transport',__('AI background poll failed.','digiforge'),['status'=>502]);
        $http=(int)wp_remote_retrieve_response_code($response);$decoded=json_decode((string)wp_remote_retrieve_body($response),true);
        if($http<200||$http>=300||!is_array($decoded))return new \WP_Error('digiforge_launch_ai_provider',__('AI provider rejected the background poll.','digiforge'),['status'=>502]);
        $status=sanitize_key((string)($decoded['status']??''));
        if(in_array($status,['queued','in_progress'],true))return ['response_id'=>$responseId,'status'=>$status];
        if($status==='incomplete'||$status==='failed'||$status==='cancelled')return new \WP_Error('digiforge_launch_ai_incomplete',__('AI background response did not complete successfully.','digiforge'),['status'=>502,'response_status'=>$status]);
        $text=$this->extractOutputText($decoded);$payload=$this->decodeJsonObject($text);
        if($status!=='completed'||!is_array($payload))return new \WP_Error('digiforge_launch_ai_invalid_json',__('AI background output was not valid structured JSON.','digiforge'),['status'=>502]);
        return ['response_id'=>$responseId,'status'=>'completed','payload'=>$payload,'model'=>sanitize_text_field((string)($decoded['model']??self::DEFAULT_MODEL)),'usage'=>is_array($decoded['usage']??null)?$decoded['usage']:[]];
    }

    private function request(string $brief, bool $webSearch, int $maxOutputTokens): array|\WP_Error
    {
        $connector=$this->connector(); if(is_wp_error($connector))return $connector;
        $apiKey=$this->secret((int)$connector['id'],'api_key'); if(is_wp_error($apiKey))return $apiKey;
        $body=['model'=>self::DEFAULT_MODEL,'input'=>$brief,'max_output_tokens'=>max(1000,min(16000,$maxOutputTokens)),'text'=>['format'=>['type'=>'json_object']]];
        if($webSearch)$body['tools']=[['type'=>'web_search']];
        $response=wp_remote_post(self::RESPONSES_URL,[
            'timeout' => 90,
            'redirection' => 0,
            'sslverify' => true,
            'reject_unsafe_urls' => true,
            'headers'=>['Authorization'=>'Bearer '.$apiKey,'Content-Type'=>'application/json'],
            'body'=>wp_json_encode($body),
        ]); unset($apiKey);
        if(is_wp_error($response)){Logger::audit('launch_openai_request_failed',['reason'=>'transport'],'launch_execution');return new \WP_Error('digiforge_launch_ai_transport',__('AI provider request failed.','digiforge'),['status'=>502]);}
        $status=(int)wp_remote_retrieve_response_code($response);$decoded=json_decode((string)wp_remote_retrieve_body($response),true);
        if($status===429)return $this->rateLimitError($response,is_array($decoded)?$decoded:[]);
        if($status<200||$status>=300||!is_array($decoded)){Logger::audit('launch_openai_request_failed',['http_status'=>$status,'request_id'=>$this->requestId($response)],'launch_execution');return new \WP_Error('digiforge_launch_ai_provider',__('AI provider rejected the request.','digiforge'),['status'=>502]);}
        $responseStatus=sanitize_key((string)($decoded['status']??''));
        if($responseStatus==='incomplete'){
            $reason=sanitize_key((string)($decoded['incomplete_details']['reason']??'unknown'));
            Logger::audit('launch_openai_incomplete',['response_id'=>sanitize_text_field((string)($decoded['id']??'')),'reason'=>$reason,'max_output_tokens'=>max(1000,min(16000,$maxOutputTokens))],'launch_execution');
            return new \WP_Error('digiforge_launch_ai_incomplete',__('AI provider response was incomplete. Retry with a smaller structured payload.','digiforge'),['status'=>502,'reason'=>$reason]);
        }
        if($responseStatus!==''&&$responseStatus!=='completed'){
            Logger::audit('launch_openai_unexpected_status',['response_id'=>sanitize_text_field((string)($decoded['id']??'')),'response_status'=>$responseStatus],'launch_execution');
            return new \WP_Error('digiforge_launch_ai_status',__('AI provider response did not complete successfully.','digiforge'),['status'=>502]);
        }
        $text=$this->extractOutputText($decoded);if($text==='')return new \WP_Error('digiforge_launch_ai_empty',__('AI provider returned no usable text.','digiforge'),['status'=>502]);
        $payload=$this->decodeJsonObject($text);
        if(!is_array($payload)){Logger::audit('launch_openai_invalid_json',['response_id'=>sanitize_text_field((string)($decoded['id']??'')),'response_status'=>$responseStatus,'output_chars'=>strlen($text),'json_error'=>sanitize_text_field(json_last_error_msg())],'launch_execution');return new \WP_Error('digiforge_launch_ai_invalid_json',__('AI provider output was not valid structured JSON.','digiforge'),['status'=>502]);}
        return ['payload'=>$payload,'response_id'=>sanitize_text_field((string)($decoded['id']??'')),'model'=>sanitize_text_field((string)($decoded['model']??self::DEFAULT_MODEL)),'usage'=>is_array($decoded['usage']??null)?$decoded['usage']:[]];
    }

    private function rateLimitError(array $response,array $decoded):\WP_Error
    {
        $providerError=is_array($decoded['error']??null)?$decoded['error']:[];$providerCode=sanitize_key((string)($providerError['code']??''));$providerType=sanitize_key((string)($providerError['type']??''));$requestId=$this->requestId($response);$retryAfter=sanitize_text_field((string)wp_remote_retrieve_header($response,'retry-after'));$context=['http_status'=>429,'provider_code'=>$providerCode,'provider_type'=>$providerType,'request_id'=>$requestId];if($retryAfter!=='')$context['retry_after']=$retryAfter;Logger::audit('launch_openai_request_failed',$context,'launch_execution');
        if(in_array($providerCode,['insufficient_quota','billing_hard_limit_reached'],true)||$providerType==='insufficient_quota')return new \WP_Error('digiforge_launch_ai_quota',__('AI provider quota or billing availability must be restored before launch research can run.','digiforge'),['status'=>429]);
        return new \WP_Error('digiforge_launch_ai_rate_limited',__('AI provider is temporarily rate limited. Retry after the provider limit resets.','digiforge'),['status'=>429]);
    }
    private function requestId(array $response):string{return sanitize_text_field((string)wp_remote_retrieve_header($response,'x-request-id'));}
    private function connector():array|\WP_Error{global $wpdb;$row=$wpdb->get_row("SELECT * FROM ".Tables::integrations()." WHERE provider='ai' AND environment='production' AND status='CONFIGURED' AND enabled=1 ORDER BY id ASC LIMIT 1",ARRAY_A);return is_array($row)?$row:new \WP_Error('digiforge_launch_ai_connector',__('An enabled production AI connector is required.','digiforge'),['status'=>409]);}
    private function secret(int $integrationId,string $name):string|\WP_Error{global $wpdb;$ciphertext=$wpdb->get_var($wpdb->prepare('SELECT ciphertext FROM '.Tables::integration_secrets().' WHERE integration_id=%d AND secret_name=%s LIMIT 1',$integrationId,$name));if(!is_string($ciphertext)||$ciphertext==='')return new \WP_Error('digiforge_launch_ai_secret',__('AI connector credential is missing.','digiforge'),['status'=>409]);try{return CredentialVault::decrypt($ciphertext,IntegrationRepository::secretContext($integrationId,$name));}catch(\Throwable $e){return new \WP_Error('digiforge_launch_ai_secret',__('AI connector credential could not be decrypted.','digiforge'),['status'=>500]);}}
    private function extractOutputText(array $response):string{if(isset($response['output_text'])&&is_string($response['output_text']))return trim($response['output_text']);$parts=[];foreach((array)($response['output']??[])as$item){if(!is_array($item))continue;foreach((array)($item['content']??[])as$content){if(is_array($content)&&isset($content['text'])&&is_string($content['text']))$parts[]=$content['text'];}}return trim(implode("\n",$parts));}
    private function decodeJsonObject(string $text):?array{$text=trim($text);$text=preg_replace('/^```(?:json)?\s*/i','',$text)??$text;$text=preg_replace('/\s*```$/','',$text)??$text;$decoded=json_decode($text,true);if(is_array($decoded))return $decoded;$start=strpos($text,'{');$end=strrpos($text,'}');if($start!==false&&$end!==false&&$end>=$start){$decoded=json_decode(substr($text,$start,$end-$start+1),true);if(is_array($decoded))return $decoded;}return null;}
}
