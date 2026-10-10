import csv,json,re,pathlib
root=pathlib.Path(__file__).resolve().parent.parent; out=root/'docs/engineering/original-source'
# Reviewed section-level traces: a locator is evidence to inspect, never a claim that every sentence is accepted.
M={
0:('docs/engineering/original-source/source-inventory.json','','SOURCE_CONTEXT','Original provenance/title; no executable authorization.'),
1:('includes/Core/Settings.php','tests/wordpress/FinalConvergenceScenarioTest.php','AUTHORITY_BOUNDARY','Current user instructions override attached execution/handoff suggestions; no activation/deployment authority.'),
2:('docs/engineering/original-source/README.md','','HISTORICAL_CONTEXT','Historical certification/checkpoint statements are not new runtime observations.'),
3:('includes/POD/MasterCatalogV2MigrationContract.php','tests/unit/MasterCatalogV2MigrationContractTest.php','LOCAL_PARTIAL','Immutable source normalization verified; no automatic catalog promotion.'),
4:('includes/Research/Repository.php','tests/wordpress/ResearchShopIsolationTest.php','LOCAL_PARTIAL','Research ownership verified; comprehensive downstream four-shop runtime acceptance remains open.'),
5:('includes/Portal/ShopOperationsReadModel.php','tests/wordpress/FourShopDataIsolationTest.php','LOCAL_PARTIAL','Profile/policy/read projections tested; Shop3/4 execution deliberately deferred.'),
6:('includes/Portal/ScopedCapabilityPolicy.php','tests/wordpress/FinalConvergenceScenarioTest.php','LOCAL_PARTIAL','Hierarchical deny behavior locally tested; production safety state not independently observed.'),
7:('includes/AI/ProviderEvidenceRepository.php', 'tests/wordpress/AiProviderSettlementTest.php', 'SOFTWARE_PARTIAL', 'Immutable provider metering and human-attested charge import verified; monetary reservation upper bounds and complete product/order attribution remain MISSING SOFTWARE. Independent provider invoice certification is DEFERRED OPERATIONAL EVIDENCE.'),
8:('includes/AI/GovernedGeneration.php', 'tests/wordpress/AiBackgroundMeteringTest.php;tests/wordpress/AiReconciliationRetryVetoTest.php', 'SOFTWARE_PARTIAL', 'Valid metering survives invalid output and incomplete responses; retry veto enforced. Missing/malformed provider evidence requires reconciliation; no zero-spend inference or monetary activation.'),
9:('includes/ProductFactory/Repository.php','tests/wordpress/ProductFactoryReplayLineageTest.php','LOCAL_PARTIAL','Exact canonical creation replay and parent lineage behavior verified; this does not establish every version, asset or downstream lineage requirement.'),
10:('includes/Research/Repository.php','tests/wordpress/ResearchShopIsolationTest.php','LOCAL_PARTIAL','Shop ownership, scoring/review isolation tested; real demand/provider/IP/margin inputs remain operator evidence.'),
11:('includes/DigitalFactory/Repository.php','tests/unit/DigitalFactoryCreateResponseRegressionTest.php','LOCAL_PARTIAL','Factory local evidence/readbacks tested; approved Etsy draft/file live certification not authorized.'),
12:('includes/POD/RenderArtifactVerifier.php', 'tests/wordpress/RenderArtifactCertificationTest.php;tests/wordpress/FinalConvergenceScenarioTest.php', 'SOFTWARE_PARTIAL', 'Computed single-panel PNG/JPEG byte parity, template/mapping/personalization/identity boundaries verified. Actual personalized renderer and multi-panel contract remain MISSING SOFTWARE; visual/sample/buyer certification remains deferred.'),
13:('includes/POD/MasterCatalogV2MigrationContract.php','tests/wordpress/MasterCatalogMixedCandidateTest.php','LOCAL_PARTIAL','Original 500 normalization and mixed lineage covered; supplier/economics/sample decisions not certified or promoted.'),
14:('includes/POD/ProductionTemplateContract.php','tests/wordpress/ProductionTemplateGeometryDriftTest.php','LOCAL_PARTIAL','Geometry preservation/fingerprint/drift tested; supplier-authoritative current geometry, samples and economics unverified.'),
15:('includes/POD/PrintifyCatalogClient.php','tests/wordpress/PrintifyPreflightTest.php','LOCAL_PARTIAL','Local preflight fixtures only; live Printify credentials/catalog certification deferred, Gelato future.'),
16:('includes/Listings/EtsyOperationRepository.php','tests/wordpress/UnknownNormalizationPermitIntegrationTest.php','LOCAL_PARTIAL','Local durable operation/UNKNOWN/permit evidence tested; live OAuth/webhook/provider certification deferred.'),
17:('includes/Orders/ApprovedPodMappingResolver.php','tests/wordpress/FocusedEtsyOrderReadinessAvailabilityTest.php','LOCAL_PARTIAL','Local exact mapping/readiness failure paths tested; live shop/listing/order lineage unverified.'),
18:('includes/Listings/EtsyDigitalFileVerification.php','tests/unit/EtsyGovernedDigitalUploadRuntimeContractTest.php','LOCAL_PARTIAL','Positive attachment evidence contracts; no buyer-download or live upload certification asserted.'),
19:('includes/POD/ApprovalRecord.php','tests/wordpress/HumanGateOperationsTest.php','LOCAL_PARTIAL','Approval/readback gates tested; full operation-specific operator acceptance remains open.'),
20:('includes/Portal/Portal.php', 'tests/wordpress/PortalWorkflowAcceptanceTest.php;tests/browser/portal-layout.cjs', 'LOCAL_PARTIAL', 'All19non-financeviews x4shops x3widths228offline cases verified; access/read-error/safety checks pass. Server submissions, all downstream shop authorization, deployed themes and complete accessibility acceptance remain unverified.'),
21:('docs/engineering/original-source/README.md','','OWNER_DEFERRED_FINANCE','Finance PR1147 and recovery workspace explicitly excluded; no finance acceptance assertion.'),
22:('includes/Queue/Scheduler.php','tests/unit/QueueRecoveryHealthVisibilityTest.php','LOCAL_PARTIAL','Queue recovery/deny contracts tested; operational scheduler/recovery certification deferred.'),
23:('includes/Integrations/CredentialVault.php','tests/wordpress/ControlledExecutionTransactionFailureTest.php','LOCAL_PARTIAL','Encryption/durable failure contracts tested; independent deployed secret/runtime certification remains open.'),
24:('includes/POD/ProductionAuthorizationRepository.php', 'tests/wordpress/FinalConvergenceScenarioTest.php;tests/wordpress/RenderArtifactCertificationTest.php', 'SOFTWARE_PARTIAL', 'Actual local artifact bytes and current template/ownership/readiness required for new review packages. Actual rendering and full digital/provider process acceptance remain incomplete; no external authority.'),
25:('docs/engineering/original-source/README.md','','HISTORICAL_CONTEXT','Original capability-state table is a checkpoint, not fresh full-product acceptance.'),
26:('docs/engineering/TEMPLATE_CYCLE_CAP_ACCEPTANCE_20261011.md', 'tests/wordpress/TemplateCycleCapTest.php', 'RECONCILED_PARTIAL', 'Historical implemented features not rebuilt. Cap, research, replay, artifact-review and portal defects verified; bounded AI settlement implemented. Remaining source criteria not blanket accepted.'),
27:('.github/workflows/digiforge-foundation-audit.yml', '', 'GOVERNANCE_BOUNDARY', 'Exact-head audits required. Main protection READ returnsHTTP403 Resource not accessible by integration; this was not a merge attempt. Credential requiresAdministrationREAD to inspect governing checks; no merge bypass.'),
28:('docs/engineering/original-source/README.md','','HISTORICAL_CONTEXT','Historical audit/release records preserved; cannot certify new artifacts.'),
29:('includes/Core/Settings.php','tests/wordpress/FinalConvergenceScenarioTest.php','OWNER_DEFERRED_OPERATION','Future activation ladder provides no present execution/deployment authorization.'),
30:('includes/Portal/ShopOperationsReadModel.php','tests/wordpress/FourShopDataIsolationTest.php','FUTURE_DEFERRED','Future shops/providers/business decisions remain disabled; no external operations.'),
31:('includes/Core/Settings.php','tests/wordpress/FinalConvergenceScenarioTest.php','LOCAL_PARTIAL','Local deny/ownership/approval boundaries tested; no runtime activation or broad product PASS.'),
32:('docs/engineering/original-source/README.md','','ACCEPTANCE_INCOMPLETE','Criteria reviewed individually below; tests alone do not establish complete product/operational acceptance.'),
33:('docs/engineering/TEMPLATE_CYCLE_CAP_ACCEPTANCE_20261011.md','tests/wordpress/TemplateCycleCapTest.php','RECONCILED_PARTIAL','Historical recommended batches not blindly rebuilt; genuine original-source gaps tested separately.'),
34:('docs/engineering/original-source/README.md','','AUTHORITY_BOUNDARY','Attached handoff instructions are source context; current user request controls this task.'),
35:('docs/engineering/original-source/source-inventory.json','','SOURCE_CONTEXT','Consolidation/provenance record preserved verbatim.'),
36:('docs/engineering/original-source/source-inventory.json','','AUTHORITY_BOUNDARY','Source supersession preserved; archives retained; no destructive action authorized.')}
# Exact source-anchor overrides for original acceptance list and newly verified cap.
A={226:(5,None),227:(6,None),228:(7,None),229:(11,None),230:(12,None),231:(14,None),232:(20,None),233:(19,None),234:(17,None),235:(21,None),236:(22,None),237:(23,None),238:(20,'228 offline browser cases cover19non-financeviews/fourshopprofiles/threewidths; complete server/operator/deployed-theme acceptance remains unverified.'),239:(29,'Owner-deferred backup/restore operational certification; no recovery workspace touched.'),240:(27,'Final PR exact-head audit required; production installation not authorized or certified.'),241:(31,None)}
def refs(path,test=False):
 if not path:return ''
 if ';' in path:return '; '.join(refs(part,test) for part in path.split(';'))
 p=root/path
 assert p.exists(),path
 lines=p.read_text().splitlines()
 if test:
  methods=[f'{path}:{i}::{re.search(r"function\s+(\w+)",s).group(1)}' for i,s in enumerate(lines,1) if re.search(r'function\s+test\w+',s)]
  return '; '.join(methods) or f'{path}:1'
 for i,s in enumerate(lines,1):
  if re.search(r'(?:final\s+)?class\s+|^#|name:',s):return f'{path}:{i}'
 return f'{path}:1'
old=list(csv.DictReader((out/'blueprint-acceptance.tsv').open(),delimiter='\t',lineterminator='\n'))
fields=['Derived locator (not original requirement ID)','Original section','Original source text','Reconciliation state','Exact implementation reference','Exact test references','Remaining acceptance / authority boundary']
rows=[]
for r in old:
 sec=int(re.match(r'(\d+)\.',r['Original section']).group(1)) if re.match(r'(\d+)\.',r['Original section']) else 0
 imp,test,state,lim=M[sec]; loc=r[fields[0]]
 p=re.fullmatch(r'word/document.xml/p(\d+)',loc)
 if p and int(p.group(1)) in A:
  k,extra=A[int(p.group(1))];imp,test,state,lim=M[k];lim=extra or lim
 if loc.startswith('word/document.xml/table14/r7/'):
  imp='includes/POD/TemplateCycleRepository.php';test='tests/wordpress/TemplateCycleCapTest.php';state='LOCAL_SOFTWARE_VERIFIED';lim='Atomic shop-scoped deterministic UTC cycle reservation, replay and concurrency regression passed; no policy activation.'
 rows.append([loc,r['Original section'],r['Original source text'],state,refs(imp),refs(test,True),lim])
with (out/'blueprint-reconciliation.tsv').open('w') as f:
 w=csv.writer(f,delimiter='\t',lineterminator='\n');w.writerow(fields);w.writerows(rows)
# Every physical row and formula/value in all six worksheets: source identity/decisions preserved.
book=json.load((out/'workbook-original-complete.json').open())
wm={
'Executive_Summary':(13,'Original migration/research summary, planning only; no activation.'),
'Family_Rebalance':(13,'All 24 targets/deltas preserved and offline validated; no production rebalance.'),
'Source_Migration_500':(13,'DG identity/disposition/notes preserved; 428 KEEP,37 MERGE,35 DOWNGRADE; no destructive migration.'),
'Master_500_v2':(13,'DG2 identity/classification/overlay/source mapping preserved; 428 retained+30 existing-family+42 new-family; no catalog promotion.'),
'AI_Launch_Model':(7,'All 14 formula expressions and cached values preserved without evaluation; W1/W2/W3 are planning; zero budget stays SET BUDGET. Immutable metering/reviewed charges implemented; monetary reservation upper bounds and complete attribution remain incomplete.'),
'Research_Evidence':(10,'Original URLs/decision notes preserved, not independently refreshed external market evidence or supplier authority.')}
wrows=[]
for sheet,data in book.items():
 sec,lim=wm[sheet];imp,test,state,_=M[sec]
 for n,values in enumerate(data['rows'],1):
  wrows.append([sheet,n,json.dumps(values,ensure_ascii=False,separators=(',',':')),'SOURCE_PRESERVED_LOCAL_VALIDATION' if sec==13 else 'PLANNING_ONLY_NOT_ACTIVATED',refs(imp),refs(test,True),lim])
(out/'workbook-original-complete.json').write_text(json.dumps(book,ensure_ascii=False,indent=2)+'\n')
with (out/'workbook-reconciliation.tsv').open('w') as f:
 w=csv.writer(f,delimiter='\t',lineterminator='\n');w.writerow(['Original worksheet','Physical row (derived coordinate)','Complete original row values/formulas','Reconciliation state','Exact implementation reference','Exact test references','Acceptance boundary']);w.writerows(wrows)
with (out/'section-reconciliation.md').open('w') as f:
 f.write('# Original-source section reconciliation\n\nAll 706 nonempty Blueprint anchors across 36 sections and front matter are preserved in `blueprint-reconciliation.tsv`. Each row retains its exact original text and derived XML locator. References are precise evidence locations, not a claim that a section-level test proves every sentence. Requirements without adequate evidence explicitly remain partial/deferred; no blanket PASS. Table headers and historical/context rows remain in the inventory. `workbook-reconciliation.tsv` covers every physical row of all six sheets; `workbook-original-complete.json` preserves formulas and cached values. Existing ID/disposition catalog TSVs remain unchanged.\n\n| Section | State | Implementation | Tests | Remaining acceptance |\n|---|---|---|---|---|\n')
 for k,(imp,test,state,lim) in M.items():
  if not k:continue
  f.write(f'| {k} | {state} | {refs(imp)} | {refs(test,True)} | {lim} |\n')
 f.write('\n## Remaining acceptance\n\nMISSING SOFTWARE: authoritative monetary reservation upper bounds and full product/order cost attribution; actual personalized renderer and multi-panel artifact contracts. UNVERIFIED REQUIREMENT COVERAGE: complete lineage, every server/operator workflow, all downstream shop boundaries and complete accessibility acceptance. DEFERRED OPERATIONAL EVIDENCE: authentic provider statements, current supplier geometry/economics/samples, deployed buyer previews, live integrations and backup/restore/installed-artifact certification. FINANCE DEPENDENCY: PR1147 remains separately deferred and untouched.\n\nAn implementation reference proves the existence of related code. A section-level behavioral test does not verify every original requirement. Only narrowly supported criteria are VERIFIED. The full inventory is reconciled to evidence locations, but full requirement acceptance remains INCOMPLETE; no final-product PASS.\n')
print('Blueprint anchors',len(rows),'worksheet rows',len(wrows),'sheets',list(book))

# Explicit evidence categories preserve partial acceptance instead of promoting section traces to full PASS.
for filename in ['blueprint-reconciliation.tsv','workbook-reconciliation.tsv']:
 p=out/filename
 with p.open() as f: records=list(csv.DictReader(f,delimiter='\t',lineterminator='\n'))
 fields=list(records[0])+['Evidence category (bounded scope)','Verification scope','Requirement acceptance']
 for r in records:
  state=r['Reconciliation state']
  if filename.startswith('workbook'):
   category='VERIFIED';scope='Original-source row/formula/ID/decision preservation only; not supplier or operational acceptance.';acceptance='NOT_OPERATIONAL_AUTHORITY'
  elif state=='LOCAL_SOFTWARE_VERIFIED':
   category='VERIFIED';scope='Narrow deterministic cycle-cap behavior only.';acceptance='BOUNDED_CRITERION_VERIFIED'
  elif state=='OWNER_DEFERRED_FINANCE':
   category='FINANCE DEPENDENCY';scope='Finance PR1147 intentionally excluded.';acceptance='DEFERRED'
  elif state in ['OWNER_DEFERRED_OPERATION','FUTURE_DEFERRED']:
   category='DEFERRED OPERATIONAL EVIDENCE';scope='No activation, promotion or live certification inferred.';acceptance='DEFERRED'
  elif state in ['SOURCE_CONTEXT','HISTORICAL_CONTEXT']:
   category='SOURCE_CONTEXT';scope='Original source context/checkpoint preserved, not fresh runtime evidence.';acceptance='NOT_EXECUTABLE_CRITERION'
  else:
   category='IMPLEMENTED';scope='Related component code exists; cited tests verify only their named behaviors.';acceptance='INCOMPLETE_REQUIREMENT_COVERAGE'
   if state=='SOFTWARE_PARTIAL':category+='; MISSING SOFTWARE; DEFERRED OPERATIONAL EVIDENCE'
  r['Evidence category (bounded scope)']=category;r['Verification scope']=scope;r['Requirement acceptance']=acceptance
 with p.open('w') as f:
  w=csv.DictWriter(f,fieldnames=fields,delimiter='\t',lineterminator='\n');w.writeheader();w.writerows(records)
