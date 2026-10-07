<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductReviewEvidencePropagationContractTest extends TestCase
{
    public function testProductFactoryReadErrorsArePropagatedBeforeAuthorityUse(): void
    {
        $source=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/ProductReview.php');
        self::assertStringContainsString("\$version = \$products->find('product_version', \$productVersionId);\n        if (is_wp_error(\$version)) { return \$version; }",$source);
        self::assertStringContainsString("\$product = \$products->find('product', \$productId);\n        if (is_wp_error(\$product)) { return \$product; }",$source);
    }
}
