# Licensing a Sellissimo site

heureka Kreatív Kft. licenses Sellissimo per production site. One license covers `sellissimo/core`, `sellissimo/stripe`, `sellissimo/mollie` and `sellissimo/shipping-flat-rate` on that site. Install whichever integrations your store needs. Third-party plugins have their own terms. Each copy carries its applicable license terms. [Sellissimo license agreement](../LICENSE)

## Development, staging and production

Development and staging are free. A site that accepts real customer orders counts as a production site, even on a staging domain. A Core site license or written authorization from heureka Kreatív Kft. covers production use. Existing written approvals remain valid for the operator, site and dates specified. Keep the approval with the site records and arrange continued permission before it expires.

Until Marketplace sales open, contact [the Sellissimo team](mailto:hello@heureka-kreativ.hu) about production authorization. Statamic’s **Utilities → Licensing** screen may show the add-on as unlisted during this period.

## Buying and renewing at Marketplace launch

The first 100 buyers pay USD 49 for one production site. The regular price is USD 99. One license covers Core, Stripe, Mollie and Flat Rate Shipping on that site and includes one year of support and updates. Renewal is optional at USD 49 per year for every buyer. You may continue using covered versions after renewal lapses. If you skip renewal, you can continue operating the store on versions covered by the license. Each additional production site needs its own license.

Once the Marketplace listing opens, activate Sellissimo through Statamic.

1. In your Statamic account, open or create the Site for your production domain.
2. Purchase the Sellissimo core license and attach it to that Site. The three official integrations share this purchase.
3. Set `STATAMIC_LICENSE_KEY` to the Site's key in your production environment. If that key is already configured, keep using it. Rebuild Laravel's configuration cache if your deployment uses it.
4. Open **Utilities → Licensing** in the Control Panel and refresh to check the core license. If the domain or license assignment needs attention, update the Site in your Statamic account. [Statamic site licensing](https://statamic.dev/getting-started/licensing) · [Statamic addon licensing](https://statamic.dev/addons/overview#licensing)

## Agencies and plugin developers

An agency can customize Sellissimo for a licensed client site. Plugin authors can adapt the bundled local-pickup example and extension generator templates and distribute their own free or paid plugins. Declare `sellissimo/core` as a dependency. Each merchant obtains Sellissimo separately. [Extension development](extensions.md) · [License agreement](../LICENSE)
