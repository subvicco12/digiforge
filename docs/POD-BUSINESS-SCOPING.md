# POD Business → Store → Product Program Scoping

DigiForge uses this ownership hierarchy for business-active POD records:

`Business → Store → Product Program → Product/Listing → Supplier Mapping → Production Template`

The supplier catalog is shared infrastructure. A Printify blueprint, provider, variant, print area, shipping observation, or other physical-product evidence MUST NOT imply a DigiForge business or store.

## Program rules

- `DigiCraftifyGoods` is restricted to `PERSONALIZED_POD`.
- Ordinary/original AI-assisted graphic POD belongs to a separate future business using `ORIGINAL_DESIGN_POD`.
- The future original-design consumer brand/store is intentionally TBD and inactive until explicitly configured; code must not invent a brand name.
- Personalized photo, pet, name, handwriting and related personalized products remain eligible for DigiCraftifyGoods even when the physical blank is also usable by another business.
- Invalid or missing business/store/program combinations fail closed.

## Shared versus scoped data

Shared supplier/catalog evidence may be synchronized once and reused across businesses. Business-active mappings, listings, designs, personalization schemas/bindings, pricing, research opportunities, mockups, SEO, publishing controls, orders, finance and analytics must be associated with an explicit scope before business execution.

A supplier product can therefore be reused without duplicating the physical catalog record while each business independently controls its own listing/design/template use.

## Activation safety

This foundation does not enable Etsy publishing, provider ordering, schedules, credentials, production automation or STOP ALL changes. Future migrations and repositories must preserve those existing operational gates.
