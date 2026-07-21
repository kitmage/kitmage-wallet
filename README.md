# Aspen Wallet
Aspen Wallet is a WordPress plugin that gives each customer a **Wallet organized into Funds**. You can grant Credits from products, spend Credits on bookings, and keep each type of Credit in its own Fund.

## Who this is for

This plugin is for teams that want to:
- Sell or grant Credits in WooCommerce.
- Reset or refresh Credits on subscription renewals.
- Let customers spend Credits in Fluent Booking.
- Keep separate Funds (for example: `general`, `coaching`, and `premium`) in a Wallet.

## What Aspen Wallet does

- **Tracks a customer's Credits in separate Funds** (integer Credits only).
- **Grants Credits from WooCommerce purchases**.
- **Supports subscription reset grants** through WooCommerce Subscriptions.
- **Checks and debits Credits for Fluent Booking events**.
- **Uses a team owner’s wallet for Teams for WooCommerce Memberships members**.
- **Provides shortcodes** for balance displays and conditional content.

## Requirements

- WordPress **6.2+**
- PHP **7.4+**
- Optional integrations for full functionality:
  - WooCommerce
  - WooCommerce Subscriptions
  - Fluent Booking
  - Teams for WooCommerce Memberships

## Installation

1. Upload the plugin folder to your WordPress site’s `wp-content/plugins/` directory.
2. In WordPress Admin, go to **Plugins**.
3. Activate **Aspen Wallet**.
4. Confirm the plugin dependencies you plan to use are also installed and active.

## Quick start

1. Go to **Wallet** in WordPress Admin and create your first Funds.
2. Open WooCommerce products and configure Wallet grants (Fund + Credits).
3. (Optional) Set subscription reset grants on subscription products.
4. Configure Wallet rules in Fluent Booking events (enable the Wallet, set the Credit cost, and choose allowed Funds).
5. Test with a customer account:
   - Purchase a product that grants Credits.
   - Verify balance updates.
   - Attempt a booking that costs Credits.

## Typical customer journey

1. Customer buys a product that grants Credits.
2. Aspen Wallet adds Credits to the configured Fund(s).
3. Customer visits a booking page.
4. Aspen Wallet checks whether the customer can afford the configured event cost.
   - If the customer is part of a Teams for WooCommerce Memberships team, the team owner's wallet is checked.
5. If affordable, booking proceeds and Credits are debited from the applicable Funds in the Wallet.
6. If not affordable, the customer sees your fallback/restriction message.

## Admin features

- Fund management (create, edit, and safely delete Funds).
- User Wallet and Credit management from wp-admin.
- Profile-level Credit editing (with capability checks).
- Booking restriction and debit rules tied to event settings.

## Shortcodes

- `[wallet_balance fund="your-fund"]`
  - Display the Credits in a Fund in the current user’s Wallet.
- `[wallet_if fund="your-fund" min="1"]...[/wallet_if]`
  - Render content only when a balance condition passes.
- `[wallet_booking event_id="123" fallback="Not enough credits."]`
  - Show booking output only when the user can afford it.

## Notes for site owners

- Aspen Wallet uses **integer credits** (no decimal balances).
- Credits are Fund-specific, and allowed Funds can be prioritized in booking rules.
- For Teams for WooCommerce Memberships users, front-end balance displays, booking affordability checks, and booking debits resolve to the team owner's wallet by default. If a user belongs to multiple teams, Aspen Wallet uses the first team returned by the Teams API unless customized with `aspen_wallet_effective_wallet_user_id`.
- Some functionality is dependency-gated and only activates when related plugins are active.
- For backward compatibility, code and stored data may still use the legacy term `bucket`. Shortcodes use `fund`; the deprecated `bucket` attribute remains accepted so existing content continues to work.

## Support and implementation details

For technical and developer-focused implementation details, see:
- `kb/dev-docs.md`
