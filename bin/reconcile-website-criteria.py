import csv,pathlib
root=pathlib.Path(__file__).resolve().parent.parent;out=root/'docs/engineering/original-source'
sources={r['Derived locator (not original requirement ID)']:r for r in csv.DictReader((out/'blueprint-acceptance.tsv').open(),delimiter='\t')}
specs=[
(226,'VERIFIED_LOCAL_BOUNDARY','includes/Portal/ShopOperationsReadModel.php','tests/wordpress/FourShopDataIsolationTest.php','Independent local shop records tested; live onboarding deferred.'),
(227,'VERIFIED_LOCAL_BOUNDARY','includes/Portal/ScopedCapabilityPolicy.php','tests/wordpress/FinalConvergenceScenarioTest.php::testScopedCapabilityRequiresGlobalShopAndWorkflow','Global and shop denial dominate narrower scopes.'),
(228,'VERIFIED_LOCAL_BOUNDARY; EXTERNAL_EVIDENCE_DEFERRED','includes/AI/MonetaryReservationRepository.php','tests/wordpress/CommercialCostKpiTest.php::testZeroAdministrativeCurrencyCannotPoisonReviewedCommercialCohort','Reviewed charge/ceiling fixtures verify software, not authentic current live provider charges.'),
(229,'INCOMPLETE_INTEGRATED_ACCEPTANCE; EXTERNAL_EVIDENCE_DEFERRED','includes/Listings/EtsyDigitalAttachmentReadModel.php','tests/wordpress/ResearchRepositoryTest.php::testResearchFlowIsDeterministicDeduplicatedAndHumanGated','Research and delivery components have tests, but this reference does not prove one complete research-to-Etsy-file scenario. Product25 untouched.'),
(230,'VERIFIED_LOCAL_BOUNDARY; EXTERNAL_EVIDENCE_DEFERRED; FINANCE_DEPENDENCY','includes/POD/DeterministicRenderWorkflow.php','tests/wordpress/RenderArtifactCertificationTest.php::testApprovedRepeatEngineReproducesActualRasterAndPdfPanels','Actual stored buyer-specific output and gate tests; supplier/live/financial certification remain absent.'),
(231,'VERIFIED_LOCAL_BOUNDARY; EXTERNAL_EVIDENCE_DEFERRED; FINANCE_DEPENDENCY','includes/POD/ProductionTemplateContract.php','tests/wordpress/RenderArtifactCertificationTest.php::testActualArtifactsBindTemplateAndApprovalThenDetectTemplateDrift','Immutable template and geometry drift denial; provider-authoritative geometry/economics not inferred.'),
(232,'VERIFIED_LOCAL_BOUNDARY; INCOMPLETE_INTEGRATED_ACCEPTANCE','includes/Portal/Portal.php','tests/wordpress/PortalWorkflowAcceptanceTest.php::testAllNonFinanceViewsAndFourShopsRenderWithoutChangingSafetyOrBusinessState','76 offline view/shop fixtures; every business action is not certified by view rendering.'),
(233,'VERIFIED_LOCAL_BOUNDARY','includes/POD/RenderArtifactVerifier.php','tests/wordpress/RenderArtifactCertificationTest.php::testDeterministicArtifactCannotUseDifferentBuyerTextOrCallerRecipe','Reconstruct exact approved template/payload/recipe/artifacts; do not fabricate owner approvals.'),
(234,'VERIFIED_LOCAL_BOUNDARY; INCOMPLETE_INTEGRATED_ACCEPTANCE','includes/Orders/Validator.php','tests/wordpress/OriginalSourceLineageAcceptanceTest.php::testReviewedResearchToImmutableVersionRetainsShopAcrossModuleBoundaries','Actual research-to-immutable-version handoffs and other-shop attribution denial tested; complete listing/order/provider permutations remain incomplete.'),
(235,'FINANCE_DEPENDENCY','includes/AI/CommercialCostKpiReadModel.php','tests/wordpress/CommercialCostKpiTest.php::testPortalShowsReviewedCohortWithoutFinanceRatiosOrExternalActions','Finance1147 remains deferred; revenue/profit ratios null, never zero.'),
(236,'VERIFIED_LOCAL_BOUNDARY; INCOMPLETE_INTEGRATED_ACCEPTANCE','includes/Portal/AttentionReadModel.php','tests/wordpress/PortalWorkflowAcceptanceTest.php::testReadFailureIsVisibleAndRecoveryViewCannotExecute','Read-only recovery visibility; all actual queue/retry/restore permutations not established by this one test.'),
(237,'VERIFIED_LOCAL_BOUNDARY; EXTERNAL_EVIDENCE_DEFERRED','includes/AI/ProviderEvidenceRepository.php','tests/wordpress/AiEvidenceWorkflowTest.php','Reviewed immutable import/access software; deployed secret/privacy/operations certification separate.'),
(238,'VERIFIED_LOCAL_BOUNDARY; EXTERNAL_EVIDENCE_DEFERRED','includes/Portal/Portal.php','tests/browser/portal-layout.cjs','390/768/1280 offline browser layouts; actual device/theme/operator certification deferred.'),
(239,'EXTERNAL_EVIDENCE_DEFERRED','includes/Portal/AttentionReadModel.php','tests/wordpress/HostingerRecoveryTransportTest.php','Local transport boundary tested; deployed backup/restore operation not authorized/performed.'),
(240,'GITHUB_PERMISSION_BLOCKER; EXTERNAL_EVIDENCE_DEFERRED','bin/package-plugin.sh','.github/workflows/digiforge-foundation-audit.yml','Exact-head audit required after publishing; no merge/deployment permitted; redirected artifact/protection reads blocked.'),
(241,'VERIFIED_LOCAL_BOUNDARY','includes/Core/Settings.php','tests/wordpress/PortalWorkflowAcceptanceTest.php::testAllNonFinanceViewsAndFourShopsRenderWithoutChangingSafetyOrBusinessState','STOP ALL ON, external safety lock ON, automation OFF remain preserved.')]
def ref(value):
 path,*method=value.split('::');lines=(root/path).read_text().splitlines();needle='function '+method[0]+'(' if method else 'class ';line=next((i for i,s in enumerate(lines,1) if needle in s),1)
 if method:assert any(needle in s for s in lines),value
 return path+':'+str(line)+('::'+method[0] if method else '')
rows=[]
for n,state,code,test,remainder in specs:
 r=sources[f'word/document.xml/p{n}'];rows.append([r['Derived locator (not original requirement ID)'],r['Original section'],r['Original source text'],state,ref(code),ref(test),remainder])
with (out/'complete-website-criteria-closure.tsv').open('w')as f:
 w=csv.writer(f,delimiter='\t',lineterminator='\n');w.writerow(['Derived source locator (not original ID)','Original section','Verbatim original requirement','Evidence classification','Exact implementation','Exact behavioral test / validation reference','Unverified remainder']);w.writerows(rows)
print('Source-specific complete-website criteria:',len(rows))
