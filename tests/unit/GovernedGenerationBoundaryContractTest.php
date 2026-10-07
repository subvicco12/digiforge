<?php
declare(strict_types=1);

/**
 * Contract test for the Product Factory AI execution budget boundary.
 * This is intentionally source-structural: it proves every known generation
 * entry point is guarded while background polling remains retrieval-only.
 */
$root=dirname(__DIR__,2);
$guard=file_get_contents($root.'/includes/AI/GovernedGeneration.php');
$engine=file_get_contents($root.'/includes/Launch/ExecutionEngine.php');
$automation=file_get_contents($root.'/includes/ProductFactory/ApprovalAutomation.php');
$orchestrator=file_get_contents($root.'/includes/ProductFactory/Orchestrator.php');
$qa=file_get_contents($root.'/includes/ProductFactory/SemanticQa.php');

foreach([$guard,$engine,$automation,$orchestrator,$qa] as $source){
    if($source===false){fwrite(STDERR,"Unable to read governed generation source.\n");exit(1);}
}
$must=[
 [$guard,'ShopAiGovernanceRepository','shared boundary evaluates shop governance'],
 [$guard,'ShopAiPlan::preflight','shared boundary performs canonical preflight'],
 [$guard,"ai_generation_cost_accounting_unavailable",'money budgets fail closed without truthful actual-cost accounting'],
 [$guard,'idempotent_replay','generation attempt replay cannot repeat provider execution'],
 [$engine,"GovernedGeneration())->research",'research generation is governed'],
 [$engine,"GovernedGeneration())->develop",'synchronous development generation is governed'],
 [$automation,"GovernedGeneration())->startBackgroundDevelop",'background generation is governed'],
 [$automation,"'qa',$brief",'QA repair is charged to QA stage'],
 [$automation,'retrieveBackground($responseId)','polling remains retrieval-only'],
 [$orchestrator,"GovernedGeneration())->develop",'fallback manifest generation is governed'],
 [$qa,"GovernedGeneration())->develop",'semantic QA generation is governed'],
];
foreach($must as [$source,$needle,$label]){
    if(!str_contains($source,$needle)){fwrite(STDERR,"Missing contract: {$label}\n");exit(1);}
}
foreach([$engine,$orchestrator,$qa] as $source){
    if(str_contains($source,'new OpenAIClient())->develop')||str_contains($source,'new OpenAIClient())->research')){
        fwrite(STDERR,"Direct synchronous provider generation bypass remains.\n");exit(1);
    }
}
if(substr_count($automation,'->startBackgroundDevelop(')!==4){
    fwrite(STDERR,"Unexpected Product Factory background generation call count.\n");exit(1);
}
if(substr_count($automation,'GovernedGeneration())->startBackgroundDevelop(')!==4){
    fwrite(STDERR,"A Product Factory background generation bypass remains.\n");exit(1);
}
if(substr_count($automation,'->retrieveBackground(')!==2){
    fwrite(STDERR,"Unexpected Product Factory polling topology.\n");exit(1);
}
echo "Governed AI generation boundary contract passed.\n";
