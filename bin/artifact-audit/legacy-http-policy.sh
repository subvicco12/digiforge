set -e
if grep -RniE --exclude-dir=.git --exclude-dir=vendor --exclude='digiforge-foundation-audit.yml' --exclude='HostingerRecoveryProvider.php' --exclude='ConnectionTester.php' --exclude='RecoveryStagingClient.php' --exclude='EtsySellerTaxonomyClient.php' --exclude='EtsyOAuth.php' --exclude='EtsyTokenManager.php' --exclude='EtsyControlledHttpExecutor.php' --exclude='OpenAIClient.php' --exclude='PrintifyCatalogClient.php' --exclude='PrintifyControlledTransport.php' --exclude='PrintifyReconciliationLookup.php' --exclude='GelatoCatalogClient.php' '(wp_remote_(get|post|request|head)|curl_init|GuzzleHttp|Requests\\)' digiforge.php includes modules automation admin; then
  echo 'Unexpected external HTTP client usage found outside audited integration clients'
  exit 1
fi
test "$(grep -c 'wp_remote_post(' includes/Operations/HostingerRecoveryProvider.php)" -eq 1
! grep -nE '(wp_remote_(get|request|head)|curl_init|GuzzleHttp|Requests\\)' includes/Operations/HostingerRecoveryProvider.php
grep -Fq 'https://developers.hostinger.com/api/hosting/v1' includes/Operations/HostingerRecoveryProvider.php
grep -Fq "'redirection' => 0" includes/Operations/HostingerRecoveryProvider.php
grep -Fq "'sslverify' => true" includes/Operations/HostingerRecoveryProvider.php
grep -Fq "'reject_unsafe_urls' => true" includes/Operations/HostingerRecoveryProvider.php
grep -Fq 'RecoveryOrchestrator::dispatchClaimMatches' includes/Operations/HostingerRecoveryProvider.php
grep -Fq 'RecoveryOrchestrator::safetyLocked()' includes/Operations/HostingerRecoveryProvider.php
grep -Fq 'CredentialVault::decrypt' includes/Operations/HostingerRecoveryProvider.php
grep -Fq 'RecoveryDispatchLedger::insert($dispatchOption' includes/Operations/HostingerRecoveryProvider.php
test "$(grep -c 'wp_remote_request' includes/Listings/EtsyControlledHttpExecutor.php)" -eq 1
grep -Fq "https://openapi.etsy.com/v3" includes/Listings/EtsyControlledHttpExecutor.php
grep -Fq "'redirection'=>0" includes/Listings/EtsyControlledHttpExecutor.php
grep -Fq "'sslverify'=>true" includes/Listings/EtsyControlledHttpExecutor.php
grep -Fq 'EtsyLiveTransportInterlock::authorize' includes/Listings/EtsyControlledHttpExecutor.php
grep -Fq 'EtsyScopedCredentialRetriever' includes/Listings/EtsyControlledHttpExecutor.php
test "$(grep -c 'wp_remote_get' includes/Integrations/EtsySellerTaxonomyClient.php)" -eq 1
! grep -nE '(wp_remote_(post|request|head)|curl_init|GuzzleHttp|Requests\\)' includes/Integrations/EtsySellerTaxonomyClient.php
grep -Fq "https://api.etsy.com/v3/application/seller-taxonomy/nodes" includes/Integrations/EtsySellerTaxonomyClient.php
grep -Fq "'redirection'=>0" includes/Integrations/EtsySellerTaxonomyClient.php
grep -Fq "'sslverify'=>true" includes/Integrations/EtsySellerTaxonomyClient.php
grep -Fq "'reject_unsafe_urls'=>true" includes/Integrations/EtsySellerTaxonomyClient.php
grep -Fq 'EtsyReadOnlyCredentialEnvelope::forIntegration' includes/Integrations/EtsySellerTaxonomyClient.php
grep -Fq 'EtsySellerTaxonomyVerifier::verify' includes/Integrations/EtsySellerTaxonomyClient.php
test "$(grep -c 'wp_remote_get' includes/Integrations/ConnectionTester.php)" -eq 1
test "$(grep -c 'wp_remote_request' includes/POD/PrintifyReconciliationLookup.php)" -eq 1
grep -Fq "'method'=>'GET'" includes/POD/PrintifyReconciliationLookup.php
grep -Fq "'redirection'=>0" includes/POD/PrintifyReconciliationLookup.php
grep -Fq "'sslverify'=>true" includes/POD/PrintifyReconciliationLookup.php
grep -Fq 'PrintifyScopedCredentialRetriever' includes/POD/PrintifyReconciliationLookup.php
! grep -nE '(wp_remote_(get|post|head)|curl_init|GuzzleHttp|Requests\\)' includes/POD/PrintifyReconciliationLookup.php
test "$(grep -c 'wp_remote_request' includes/POD/PrintifyControlledTransport.php)" -eq 1
grep -Fq "https://api.printify.com/v1" includes/POD/PrintifyControlledTransport.php
grep -Fq "'redirection'=>0" includes/POD/PrintifyControlledTransport.php
grep -Fq "'sslverify'=>true" includes/POD/PrintifyControlledTransport.php
grep -Fq 'PrintifyLiveTransportInterlock::authorize' includes/POD/PrintifyControlledTransport.php
grep -Fq 'PrintifyScopedCredentialRetriever' includes/POD/PrintifyControlledTransport.php
! grep -nE '(wp_remote_(get|post|head)|curl_init|GuzzleHttp|Requests\\)' includes/POD/PrintifyControlledTransport.php
test "$(grep -c 'wp_remote_get' includes/POD/PrintifyCatalogClient.php)" -eq 1
grep -Fq "https://product.gelatoapis.com/v3/" includes/POD/GelatoCatalogClient.php
grep -Fq "'X-API-KEY'=>\$apiKey" includes/POD/GelatoCatalogClient.php
grep -Fq "'redirection'=>0" includes/POD/GelatoCatalogClient.php
grep -Fq "'sslverify'=>true" includes/POD/GelatoCatalogClient.php
grep -Fq "'reject_unsafe_urls'=>true" includes/POD/GelatoCatalogClient.php
grep -Fq "MAX_ATTEMPTS=3" includes/POD/GelatoCatalogClient.php
grep -Fq "MAX_RETRY_AFTER_SECONDS=60" includes/POD/GelatoCatalogClient.php
! grep -nE '(wp_remote_(post|request|head)|curl_init|GuzzleHttp|Requests\\)' includes/POD/PrintifyCatalogClient.php
grep -Fq "https://api.printify.com/" includes/POD/PrintifyCatalogClient.php
grep -Fq "'redirection'=>0" includes/POD/PrintifyCatalogClient.php
grep -Fq "'sslverify'=>true" includes/POD/PrintifyCatalogClient.php
! grep -nE '(wp_remote_(post|request|head)|curl_init|GuzzleHttp|Requests\\)' includes/Integrations/ConnectionTester.php
grep -Fq "https://api.printify.com/v1/shops.json" includes/Integrations/ConnectionTester.php
grep -Fq "https://api.etsy.com/v3/application/openapi-ping" includes/Integrations/ConnectionTester.php
grep -Fq "https://api.etsy.com/v3/application/users/" includes/Integrations/ConnectionTester.php
grep -Fq "https://product.gelatoapis.com/v3/catalogs" includes/Integrations/ConnectionTester.php
grep -Fq "https://api.openai.com/v1/models" includes/Integrations/ConnectionTester.php
grep -Fq "'redirection' => 0" includes/Integrations/ConnectionTester.php
grep -Fq "'sslverify' => true" includes/Integrations/ConnectionTester.php
grep -Fq "'reject_unsafe_urls' => true" includes/Integrations/ConnectionTester.php
test "$(grep -c 'wp_remote_post' includes/Integrations/EtsyOAuth.php)" -eq 1
! grep -nE '(wp_remote_(get|request|head)|curl_init|GuzzleHttp|Requests\\)' includes/Integrations/EtsyOAuth.php
grep -Fq "https://www.etsy.com/oauth/connect" includes/Integrations/EtsyOAuth.php
grep -Fq "https://api.etsy.com/v3/public/oauth/token" includes/Integrations/EtsyOAuth.php
grep -Fq "'redirection' => 0" includes/Integrations/EtsyOAuth.php
grep -Fq "'sslverify' => true" includes/Integrations/EtsyOAuth.php
grep -Fq "'reject_unsafe_urls' => true" includes/Integrations/EtsyOAuth.php
grep -Fq "'code_challenge_method' => 'S256'" includes/Integrations/EtsyOAuth.php
test "$(grep -c 'wp_remote_post' includes/Integrations/EtsyTokenManager.php)" -eq 1
! grep -nE '(wp_remote_(get|request|head)|curl_init|GuzzleHttp|Requests\\)' includes/Integrations/EtsyTokenManager.php
grep -Fq "https://api.etsy.com/v3/public/oauth/token" includes/Integrations/EtsyTokenManager.php
grep -Fq "'redirection' => 0" includes/Integrations/EtsyTokenManager.php
grep -Fq "'sslverify' => true" includes/Integrations/EtsyTokenManager.php
grep -Fq "'reject_unsafe_urls' => true" includes/Integrations/EtsyTokenManager.php
test "$(grep -c 'wp_remote_post' includes/Launch/OpenAIClient.php)" -eq 3
test "$(grep -c 'wp_remote_get' includes/Launch/OpenAIClient.php)" -eq 1
! grep -nE '(wp_remote_(request|head)|curl_init|GuzzleHttp|Requests\\)' includes/Launch/OpenAIClient.php
grep -Fq "https://api.openai.com/v1/responses" includes/Launch/OpenAIClient.php
grep -Fq "'redirection' => 0" includes/Launch/OpenAIClient.php
grep -Fq "'sslverify' => true" includes/Launch/OpenAIClient.php
grep -Fq "'reject_unsafe_urls' => true" includes/Launch/OpenAIClient.php
