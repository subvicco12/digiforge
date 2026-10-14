<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

/** Stored parent identity is checked before the bounded preview and aggregate count. */
final class SelectedShopDownstreamInboxTest extends WP_UnitTestCase
{
    protected function setUp():void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        ShopAccessFixture::reset();
        DigiForge\Core\Settings::protectProduction();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
    }

    private function inbox(string $shop):string
    {
        $get=$_GET;
        try {
            $_GET=['df_view'=>'approvals','df_shop'=>$shop];
            (new DigiForge\Portal\Portal())->render();
            return (new DigiForge\Portal\U3ApprovalInbox())->appendPanel('<main class="df-portal-shell"></main>','digiforge_admin_portal',[],[]);
        } finally { $_GET=$get; }
    }

    private function listing(string $shop,string $title):int
    {
        global $wpdb;
        $now=current_time('mysql',true);
        self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::listings(),['product_version_id'=>1,'environment'=>'sandbox','shop_reference'=>$shop,'title'=>$title,'created_at'=>$now,'updated_at'=>$now]));
        $id=(int)$wpdb->insert_id;
        self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::listing_readiness_reviews(),['listing_id'=>$id,'readiness'=>'{}','readiness_hash'=>hash('sha256',$title),'created_at'=>$now,'updated_at'=>$now]));
        return (int)$wpdb->insert_id;
    }

    private function pendingCount(string $html):int
    {
        self::assertSame(1,preg_match('~Downstream pending decisions</span><b>([0-9]+)</b>~',$html,$matches));
        return (int)$matches[1];
    }

    public function testFourShopSelectionExcludesForeignRowsBeforeLimitAndCountsOnlyOwnedPending():void
    {
        $shops=DigiForge\Security\ShopAccessPolicy::canonicalShops();
        ShopAccessFixture::authorizeActor(get_current_user_id(),$shops,['manage_digiforge','manage_digiforge_research','manage_digiforge_products','manage_digiforge_listings','manage_digiforge_orders','manage_digiforge_pod']);
        $before=[];foreach($shops as $shop)$before[$shop]=$this->pendingCount($this->inbox($shop));
        $marker='Inbox '.wp_generate_uuid4();$ids=[];
        foreach($shops as $shop)$ids[$shop]=$this->listing($shop,$marker.' '.$shop);
        for($i=0;$i<55;$i++)$latestJewelry=$this->listing('jewelry',$marker.' foreign '.$i);
        foreach($shops as $shop){$html=$this->inbox($shop);self::assertStringContainsString('Consolidated approval gates',$html);if($shop!=='jewelry')self::assertStringContainsString('>Listing #'.$this->listingId($ids[$shop]).'<',$html);self::assertStringContainsString('df_shop='.$shop,$html);foreach($shops as $other)if($other!==$shop)self::assertStringNotContainsString('>Listing #'.$this->listingId($ids[$other]).'<',$html);}
        self::assertSame($before['digital']+1,$this->pendingCount($this->inbox('digital')));
        $jewelry=$this->inbox('jewelry');self::assertSame($before['jewelry']+56,$this->pendingCount($jewelry));self::assertStringContainsString('>Listing #'.$this->listingId($latestJewelry).'<',$jewelry);self::assertStringNotContainsString('>Listing #'.$this->listingId($ids['jewelry']).'<',$jewelry);self::assertStringContainsString('most recent 50',$jewelry);
    }

    private function listingId(int $reviewId):int
    {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT listing_id FROM '.DigiForge\Database\Tables::listing_readiness_reviews().' WHERE id=%d',$reviewId));
    }

    public function testOrderAndPersonalizationAncestryAndConflictingPlanFailClosed():void
    {
        global $wpdb;
        ShopAccessFixture::authorizeActor(get_current_user_id(),DigiForge\Security\ShopAccessPolicy::canonicalShops(),['manage_digiforge','manage_digiforge_research','manage_digiforge_products','manage_digiforge_listings','manage_digiforge_orders','manage_digiforge_pod']);
        $before=$this->pendingCount($this->inbox('digital'));
        $now=current_time('mysql',true);$marker='Scoped downstream '.wp_generate_uuid4();$orders=[];
        foreach(['digital','personalized_pod'] as $shop){
            $order=(new DigiForge\Orders\Repository())->createOrder(['shop_reference'=>$shop,'external_order_reference'=>$marker.$shop,'environment'=>'sandbox']);self::assertNotWPError($order);$orders[$shop]=(int)$order['id'];
            self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::order_line_items(),['order_id'=>$orders[$shop],'product_version_id'=>1,'environment'=>'sandbox','created_at'=>$now,'updated_at'=>$now]));$line=(int)$wpdb->insert_id;
            self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::personalization_schemas(),['product_version_id'=>1,'schema_key'=>'fixture-'.$shop,'version_label'=>'v1','field_definitions'=>'{}','state'=>'APPROVED','created_at'=>$now,'updated_at'=>$now]));$schema=(int)$wpdb->insert_id;
            self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::personalization_submissions(),['order_line_item_id'=>$line,'personalization_schema_id'=>$schema,'canonical_payload'=>'{}','payload_hash'=>hash('sha256',$marker.$shop),'environment'=>'sandbox','created_at'=>$now,'updated_at'=>$now]));
            self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::fulfillment_plans(),['order_id'=>$orders[$shop],'plan_version'=>'v1','canonical_payload'=>'{}','payload_hash'=>hash('sha256',$marker.'plan'.$shop),'readiness'=>'{}','readiness_hash'=>hash('sha256',$marker.'ready'.$shop),'environment'=>'sandbox','created_at'=>$now,'updated_at'=>$now]));$plans[$shop]=(int)$wpdb->insert_id;
            self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::fulfillment_readiness_reviews(),['order_id'=>$orders[$shop],'fulfillment_plan_id'=>$plans[$shop],'readiness'=>'{}','readiness_hash'=>hash('sha256',$marker.'review'.$shop),'created_at'=>$now,'updated_at'=>$now]));
        }
        $digital=$this->inbox('digital');self::assertSame($before+2,$this->pendingCount($digital));self::assertStringContainsString('Order #'.$orders['digital'],$digital);self::assertStringNotContainsString('Order #'.$orders['personalized_pod'],$digital);
        // A row claiming a digital order with a foreign plan cannot inherit either shop.
        self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::fulfillment_readiness_reviews(),['order_id'=>$orders['digital'],'fulfillment_plan_id'=>$plans['personalized_pod'],'readiness'=>'{}','readiness_hash'=>hash('sha256',$marker.'conflict'),'created_at'=>$now,'updated_at'=>$now]));
        self::assertSame($before+2,$this->pendingCount($this->inbox('digital')));
    }

    public function testPodReviewUsesStoredProductResearchLineageAndExcludesConflicts():void
    {
        global $wpdb;
        ShopAccessFixture::authorizeActor(get_current_user_id(),DigiForge\Security\ShopAccessPolicy::canonicalShops(),['manage_digiforge','manage_digiforge_research','manage_digiforge_products','manage_digiforge_listings','manage_digiforge_orders','manage_digiforge_pod']);
        $before=$this->pendingCount($this->inbox('personalized_pod'));
        $marker='Pod inbox '.wp_generate_uuid4();$research=new DigiForge\Research\Repository();
        $candidate=$research->createCandidate(['shop_key'=>'personalized_pod','title'=>$marker]);self::assertNotWPError($candidate);
        self::assertNotWPError($research->review((int)$candidate['id'],'APPROVED','Local fixture only.'));
        $opportunity=$research->promote((int)$candidate['id']);self::assertNotWPError($opportunity);
        $factory=new DigiForge\ProductFactory\Repository();
        $family=$factory->create('product_family',['opportunity_id'=>$opportunity['id'],'name'=>$marker]);self::assertNotWPError($family);
        $product=$factory->create('product',['product_family_id'=>$family['id'],'name'=>$marker]);self::assertNotWPError($product);
        $version=$factory->create('product_version',['product_id'=>$product['id'],'version_label'=>'v1']);self::assertNotWPError($version);
        $now=current_time('mysql',true);
        self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::pod_mappings(),['product_version_id'=>$version['id'],'production_plan_id'=>0,'provider'=>'printify','environment'=>'sandbox','provider_product_key'=>$marker,'mapping_version'=>'v1','created_at'=>$now,'updated_at'=>$now]));$mapping=(int)$wpdb->insert_id;
        self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::pod_readiness_reviews(),['provider_mapping_id'=>$mapping,'readiness'=>'{}','readiness_hash'=>hash('sha256',$marker),'created_at'=>$now,'updated_at'=>$now]));
        $html=$this->inbox('personalized_pod');self::assertStringContainsString('POD mapping #'.$mapping,$html);self::assertSame($before+1,$this->pendingCount($html));
        self::assertStringNotContainsString('POD mapping #'.$mapping,$this->inbox('digital'));
        $conflict=$research->createCandidate(['shop_key'=>'digital','title'=>$marker.' conflict']);self::assertNotWPError($conflict);
        self::assertSame(1,$wpdb->update(DigiForge\Database\Tables::research_candidates(),['opportunity_id'=>$opportunity['id']],['id'=>$conflict['id']]));
        self::assertStringNotContainsString('POD mapping #'.$mapping,$this->inbox('personalized_pod'));
        self::assertSame($before,$this->pendingCount($this->inbox('personalized_pod')));
    }

    public function testUnknownOwnerAndRestrictedGrantDoNotRevealDownstreamRows():void
    {
        ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge','manage_digiforge_research','manage_digiforge_products','manage_digiforge_listings','manage_digiforge_orders','manage_digiforge_pod']);
        $before=$this->pendingCount($this->inbox('digital'));
        $known=$this->listing('digital','Known '.wp_generate_uuid4());
        $unknown=$this->listing('unassigned','Unknown '.wp_generate_uuid4());
        $foreign=$this->listing('personalized_pod','Foreign '.wp_generate_uuid4());
        $html=$this->inbox('digital');self::assertStringContainsString('Listing #'.$this->listingId($known),$html);
        foreach([$unknown,$foreign] as $id)self::assertStringNotContainsString('Listing #'.$this->listingId($id),$html);
        self::assertSame($before+1,$this->pendingCount($html));
        self::assertStringNotContainsString('Consolidated approval gates',$this->inbox('personalized_pod'));
        self::assertStringNotContainsString('Consolidated approval gates',$this->inbox('all'));
    }

    public function testFailedScopedReadDoesNotBecomeAnEmptyGroupOrNumericAggregate():void
    {
        ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge','manage_digiforge_research','manage_digiforge_products','manage_digiforge_listings','manage_digiforge_orders','manage_digiforge_pod']);
        $table=DigiForge\Database\Tables::listing_readiness_reviews();
        $filter=static fn(string $sql):string=>str_contains($sql,'FROM '.$table.' r LEFT JOIN')?str_replace($table,'digiforge_missing_scoped_review_fixture',$sql):$sql;
        global $wpdb;$suppressed=$wpdb->suppress_errors(true);add_filter('query',$filter);
        try{$html=$this->inbox('digital');}finally{remove_filter('query',$filter);$wpdb->suppress_errors($suppressed);}
        self::assertStringContainsString('Downstream pending decisions</span><b>UNKNOWN</b>',$html);
        self::assertStringContainsString('Pending evidence unavailable',$html);
    }
}
