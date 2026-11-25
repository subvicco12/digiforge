// Synthetic vectors projected by the packaged PHP contract. No real provider data.
export const fixtureVectors = [
  {
    "operation": "SHOPS_READ",
    "selection": [],
    "path": "/v1/shops.json",
    "body": [
      {
        "id": 107,
        "title": "Synthetic shop",
        "sales_channel": "api"
      }
    ],
    "projection": {
      "shops": [
        {
          "id": 107,
          "title": "Synthetic shop",
          "sales_channel": "api"
        }
      ],
      "account_shop_set_sha256": "dbd59852f46207af2d5daa2e1b546a20b719f1648b90cf70d7756aceed84967f",
      "other_shop_data_disclosed": false
    }
  },
  {
    "operation": "BLUEPRINTS_READ",
    "selection": [],
    "path": "/v1/catalog/blueprints.json",
    "body": [
      {
        "id": 42,
        "title": "Synthetic mug",
        "description": "Local fixture",
        "brand": "Synthetic",
        "model": "Mug"
      }
    ],
    "projection": [
      {
        "id": 42,
        "title": "Synthetic mug"
      }
    ]
  },
  {
    "operation": "BLUEPRINT_PROVIDERS_READ",
    "selection": {
      "blueprint_id": 42
    },
    "path": "/v1/catalog/blueprints/42/print_providers.json",
    "body": [
      {
        "id": 7,
        "title": "Synthetic printer",
        "decoration_methods": [
          "sublimation"
        ],
        "production_time": null
      }
    ],
    "projection": [
      {
        "id": 7,
        "title": "Synthetic printer"
      }
    ]
  },
  {
    "operation": "VARIANTS_READ",
    "selection": {
      "blueprint_id": 42,
      "provider_id": 7
    },
    "path": "/v1/catalog/blueprints/42/print_providers/7/variants.json",
    "body": {
      "id": 42,
      "title": "Synthetic mug",
      "variants": [
        {
          "id": 9,
          "title": "Synthetic white mug",
          "options": {
            "color": "White"
          },
          "placeholders": [
            {
              "position": "front",
              "width": 1200,
              "height": 600
            },
            {
              "position": "back",
              "width": 1200,
              "height": 600
            }
          ]
        },
        {
          "id": 10,
          "title": "Synthetic blue mug",
          "options": {
            "color": "Blue"
          },
          "placeholders": [
            {
              "position": "front",
              "width": 1500,
              "height": 750
            },
            {
              "position": "back",
              "width": 1400,
              "height": 700
            }
          ]
        }
      ]
    },
    "projection": {
      "blueprint_id": 42,
      "variants": [
        {
          "id": 9,
          "title": "Synthetic white mug"
        },
        {
          "id": 10,
          "title": "Synthetic blue mug"
        }
      ]
    }
  },
  {
    "operation": "VARIANT_READ",
    "selection": {
      "blueprint_id": 42,
      "provider_id": 7,
      "variant_id": 9
    },
    "path": "/v1/catalog/blueprints/42/print_providers/7/variants.json",
    "body": {
      "id": 42,
      "title": "Synthetic mug",
      "variants": [
        {
          "id": 9,
          "title": "Synthetic white mug",
          "options": {
            "color": "White"
          },
          "placeholders": [
            {
              "position": "front",
              "width": 1200,
              "height": 600
            },
            {
              "position": "back",
              "width": 1200,
              "height": 600
            }
          ]
        },
        {
          "id": 10,
          "title": "Synthetic blue mug",
          "options": {
            "color": "Blue"
          },
          "placeholders": [
            {
              "position": "front",
              "width": 1500,
              "height": 750
            },
            {
              "position": "back",
              "width": 1400,
              "height": 700
            }
          ]
        }
      ]
    },
    "projection": {
      "id": 9,
      "title": "Synthetic white mug",
      "print_areas": [
        {
          "position": "front",
          "width": 1200,
          "height": 600,
          "bleed": null,
          "safe_zone": null
        },
        {
          "position": "back",
          "width": 1200,
          "height": 600,
          "bleed": null,
          "safe_zone": null
        }
      ]
    }
  },
  {
    "operation": "CATALOG_SHIPPING_METHOD_READ",
    "selection": {
      "blueprint_id": 42,
      "provider_id": 7,
      "method": "standard"
    },
    "path": "/v2/catalog/blueprints/42/print_providers/7/shipping/standard.json",
    "body": {
      "data": [
        {
          "id": "9",
          "type": "variant_shipping_standard_us",
          "attributes": {
            "shippingType": "standard",
            "country": {
              "code": "US"
            },
            "variantId": 9,
            "shippingPlanId": "synthetic-standard",
            "handlingTime": {
              "from": 2,
              "to": 5
            },
            "shippingCost": {
              "firstItem": {
                "amount": 500,
                "currency": "USD"
              },
              "additionalItems": {
                "amount": 200,
                "currency": "USD"
              }
            }
          }
        }
      ]
    },
    "projection": {
      "rates": [
        {
          "variant_id": 9,
          "method": "standard",
          "country": "US",
          "first_item": 500,
          "additional_item": 200,
          "currency": "USD"
        }
      ],
      "address_quote": false
    }
  },
  {
    "operation": "PRODUCTS_READ",
    "selection": {
      "page": 1,
      "limit": 10
    },
    "path": "/v1/shops/107/products.json?page=1&limit=10",
    "body": {
      "data": [
        {
          "id": "product-1",
          "shop_id": 107,
          "title": "Synthetic mug",
          "blueprint_id": 42,
          "print_provider_id": 7,
          "variants": [
            {
              "id": 9,
              "price": 1000,
              "is_enabled": true
            }
          ],
          "print_areas": []
        }
      ],
      "current_page": 1,
      "per_page": 10,
      "last_page": 1,
      "total": 1
    },
    "projection": {
      "data": [
        {
          "id": "product-1",
          "blueprint_id": 42,
          "print_provider_id": 7,
          "variants": [
            {
              "id": 9,
              "price": 1000,
              "is_enabled": true
            }
          ],
          "print_areas": [],
          "images": [],
          "supplier_certified": false
        }
      ],
      "current_page": 1,
      "last_page": 1,
      "per_page": 10,
      "next_page": null,
      "pagination_followed": false
    }
  },
  {
    "operation": "PRODUCT_READ",
    "selection": {
      "product_id": "product-1",
      "blueprint_id": 42,
      "provider_id": 7,
      "variant_ids": [
        9
      ]
    },
    "path": "/v1/shops/107/products/product-1.json",
    "body": {
      "id": "product-1",
      "shop_id": 107,
      "title": "Synthetic mug",
      "blueprint_id": 42,
      "print_provider_id": 7,
      "variants": [
        {
          "id": 9,
          "price": 1000,
          "is_enabled": true
        }
      ],
      "print_areas": []
    },
    "projection": {
      "id": "product-1",
      "blueprint_id": 42,
      "print_provider_id": 7,
      "variants": [
        {
          "id": 9,
          "price": 1000,
          "is_enabled": true
        }
      ],
      "print_areas": [],
      "images": [],
      "supplier_certified": false
    }
  },
  {
    "operation": "ORDERS_READ",
    "selection": {
      "page": 1,
      "limit": 10
    },
    "path": "/v1/shops/107/orders.json?page=1&limit=10",
    "body": {
      "data": [
        {
          "id": "order-1",
          "shop_id": 107,
          "external_id": "fixture-order",
          "status": "partially-fulfilled",
          "line_items": [
            {
              "product_id": "product-1",
              "variant_id": 9,
              "quantity": 1
            }
          ],
          "shipments": [
            {
              "carrier": "FixtureCarrier",
              "number": "fixture-tracking-1",
              "url": "https://example.invalid/receipt"
            }
          ]
        }
      ],
      "current_page": 1,
      "per_page": 10,
      "last_page": 1,
      "total": 1
    },
    "projection": {
      "data": [
        {
          "id": "order-1",
          "external_id": "fixture-order",
          "sent_to_production_at": null,
          "status": "partially-fulfilled",
          "line_items": [
            {
              "product_id": "product-1",
              "variant_id": 9,
              "quantity": 1,
              "cost": null,
              "shipping_cost": null
            }
          ],
          "shipments": [
            {
              "carrier": "FixtureCarrier",
              "tracking_hash": "69841eab5c0335688bbfa97029834672e823e4029ea5a147b0b936f8b67b1cac",
              "url_observed": true,
              "delivered_at": null
            }
          ],
          "cost": null,
          "shipping_cost": null,
          "production_certified": false
        }
      ],
      "current_page": 1,
      "last_page": 1,
      "per_page": 10,
      "next_page": null,
      "pagination_followed": false
    }
  },
  {
    "operation": "ORDER_READ",
    "selection": {
      "order_id": "order-1"
    },
    "path": "/v1/shops/107/orders/order-1.json",
    "body": {
      "id": "order-1",
      "shop_id": 107,
      "external_id": "fixture-order",
      "status": "partially-fulfilled",
      "line_items": [
        {
          "product_id": "product-1",
          "variant_id": 9,
          "quantity": 1
        }
      ],
      "shipments": [
        {
          "carrier": "FixtureCarrier",
          "number": "fixture-tracking-1",
          "url": "https://example.invalid/receipt"
        }
      ]
    },
    "projection": {
      "id": "order-1",
      "external_id": "fixture-order",
      "sent_to_production_at": null,
      "status": "partially-fulfilled",
      "line_items": [
        {
          "product_id": "product-1",
          "variant_id": 9,
          "quantity": 1,
          "cost": null,
          "shipping_cost": null
        }
      ],
      "shipments": [
        {
          "carrier": "FixtureCarrier",
          "tracking_hash": "69841eab5c0335688bbfa97029834672e823e4029ea5a147b0b936f8b67b1cac",
          "url_observed": true,
          "delivered_at": null
        }
      ],
      "cost": null,
      "shipping_cost": null,
      "production_certified": false
    }
  }
];
