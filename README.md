# WC Order Timeline Tracking

**WC Order Timeline Tracking** is a feature-packed WooCommerce extension that provides personalized, step-by-step order tracking timeline management. It features custom timeline milestones, status presets, administrative timeline managers, and automatic real-time sync with **17TRACK API**, **Track123 API**, **Packlink PRO API**, and **FedEx REST APIs**.

---

## 🚀 Key Features

* **Custom Order Timelines**: Easily manage, add, edit, and reorder step-by-step tracking events for WooCommerce orders.
* **Status & Preset Management**: Define and save timeline step templates/presets for fast application to orders.
* **Automated 17TRACK Sync**: Auto-fetch live shipping carrier updates using the 17TRACK API background cron job.
* **Automated Track123 Sync**: Auto-fetch live shipment updates using the Track123 REST API v2.1 across 1,700+ global couriers.
* **Packlink PRO Sync**: Auto-fetch tracking events directly from your Packlink PRO shipment references (requires the Packlink PRO Shipping plugin).
* **Automated FedEx Sync**: Auto-fetch tracking events directly using FedEx REST APIs via OAuth 2.0 (supports Production and Sandbox environments).
* **Carrier Auto-Detection**: Automatically detect courier services based on the tracking number (17TRACK and Track123 providers).
* **Frontend Tracking Shortcode**: Display a sleek, modern, mobile-responsive tracking timeline to customers using `[wc_order_timeline_tracking]`.
* **Refund Column Integration**: Adds timeline visibility directly inside WooCommerce admin order lists.
* **Order List Quick Action**: One-click quick button in WooCommerce admin order lists (both classic CPT and HPOS) to jump straight to an order's tracking edit page.

---

## 📋 Requirements

* **WordPress**: 6.0 or higher
* **WooCommerce**: 7.0 or higher (Tested up to 9.9)
* **PHP**: 8.0 or higher
* **17TRACK Account & API Key** *(Optional, for 17TRACK automated carrier tracking)*
* **Track123 Account & API Key / Secret** *(Optional, for Track123 automated tracking)*
* **Packlink PRO Account, API Key & the [Packlink PRO Shipping](https://wordpress.org/plugins/packlink-pro-shipping/) plugin** *(Optional, for Packlink automated tracking)*
* **FedEx Developer Account & Project API Credentials** *(Optional, for FedEx REST automated tracking)*

---

## 📦 Installation

1. Download or clone this repository into your WordPress plugin directory:
   ```bash
   wp-content/plugins/wc-order-timeline-tracking
   ```
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Upon activation, the database table (`wp_order_timeline`) and settings will be initialized automatically.

---

## 💻 Usage & Shortcode

### Frontend Order Tracking
Add the shortcode to any page, post, or layout block where you want customers to look up their tracking status:

```text
[wc_order_timeline_tracking]
```

When a user visits the page, they can enter their tracking code into the search box. Alternatively, send customers directly to the tracking link via query parameters:
`https://yourdomain.com/tracking-page/?tracking=YOUR_TRACKING_CODE`

### Admin Management
* **Timeline Tracking Menu**: Access global timelines, presets, and settings via the main admin navigation menu (**Timeline Tracking**).
* **Order Meta Box**: Manage order-specific tracking codes, estimated delivery dates, and timeline steps directly within the WooCommerce Order details screen.

---

## ⚙️ 17TRACK Integration & Auto-Sync

1. Navigate to **Timeline Tracking > ⚙ Settings**.
2. Input your **17TRACK API Key**.
3. Set your desired **Sync Interval** (in hours) and **Inactivity Threshold** (in days).
4. Save settings. The plugin will automatically schedule background WP-Cron jobs (`wcotl_auto_sync`) to query 17TRACK for active shipments and add new tracking steps seamlessly.

---

## 🚚 Track123 Integration & Auto-Sync

Track123 automated tracking connects to the [Track123 REST API v2.1](https://docs.track123.com/reference/request) to support over 1,700 couriers worldwide with multi-carrier checkpoint synchronization and carrier auto-detection.

1. Obtain your API Key from the [Track123 Admin Portal](https://member.track123.com/dashboard) under **Developers > API**.
2. Navigate to **Timeline Tracking > ⚙ Settings**.
3. In the **Track123 API** section, enter your **Track123 API Key / Secret** and click **Save Track123 Settings**.
4. On any tracking code detail screen, select **Track123** as the provider and enter the carrier tracking number (e.g. `9400111899223857481234`).
5. Optionally click **🔍 Detect carrier** to let Track123 detect the carrier, or select a courier from the search panel. Leave as "Auto-detect" to let Track123 detect the courier automatically during registration.
6. The background cron job handles registering the shipment, querying tracking updates, deduplicating milestones, and inserting timeline steps automatically.

---

## 📦 Packlink PRO Integration & Auto-Sync

Packlink PRO tracking is available **only when the [Packlink PRO Shipping for WooCommerce](https://wordpress.org/plugins/packlink-pro-shipping/) plugin is installed and active**. Unlike 17TRACK and Track123, Packlink tracks by its own **Shipment Reference** (e.g. `ESXXXXXXXXXXXXXX`) — no carrier code is required.

1. Ensure the **Packlink PRO Shipping** plugin is installed and active.
2. Navigate to **Timeline Tracking > ⚙ Settings**.
3. Input your **Packlink PRO API Key** (obtain it from your Packlink PRO dashboard under **Settings → Packlink PRO API**).
4. Save settings.
5. **Order Page & Auto-Import Prefill**:
   - For any WooCommerce order shipped via Packlink PRO, the order sidebar meta box automatically detects the Packlink shipment: custom **Carrier name** (e.g. `brt - 2 DAYS`), **Carrier tracking codes** (e.g. Bartolini code), **Packlink Reference number**, and **Packlink shipping price**.
   - Creating a store tracking code from the order keeps the standard `TRK-` store tracking code format for your customers while automatically prefilling the Packlink Reference number (or carrier code) in the Auto-Tracking section for instant imports.
6. On each tracking code's detail page, select **Packlink PRO** as the provider and enter the Packlink **Shipment Reference** as the tracking reference (or click the one-click prefill button).
7. The background cron job auto-fetches updates from Packlink and adds them as timeline steps.

---

## ✈️ FedEx Integration & Auto-Sync

FedEx automated tracking connects directly to the [FedEx Track API](https://developer.fedex.com/wirc/browser/en-it/catalog/track/docs) via [FedEx OAuth 2.0 Authorization](https://developer.fedex.com/wirc/browser/en-it/catalog/authorization/docs).

1. Create a project in the [FedEx Developer Portal](https://developer.fedex.com/) to obtain your **API Key (Client ID)** and **Secret Key (Client Secret)**.
2. Navigate to **Timeline Tracking > ⚙ Settings**.
3. In the **FedEx API** section:
   - Enter your **FedEx API Key / Client ID**.
   - Enter your **FedEx Secret Key / Client Secret**.
   - Select the target environment: **Production** (`apis.fedex.com`) or **Sandbox** (`apis-sandbox.fedex.com`).
4. Save settings.
5. On any tracking code detail screen, select **FedEx** as the provider and enter the real FedEx tracking number (e.g. `123456789012`).
6. The background cron job handles OAuth token generation, automatic token expiration caching, detailed scan retrieval, and timeline step creation.

### Provider Comparison

| Feature | 17TRACK | Track123 | Packlink PRO | FedEx |
|---|---|---|---|---|
| Tracking identifier | Carrier tracking number | Carrier tracking number | Packlink Shipment Reference | FedEx Tracking Number |
| Authentication | Static API Token (`17token` header) | API Key (`Track123-Api-Secret` header) | API Key header | OAuth 2.0 Bearer Token (Client Credentials) |
| Environments | Production | Production | Production | Production & Sandbox |
| Carrier selection | Auto-detect or carrier code | Auto-detect or courier code | N/A | Dedicated FedEx carrier |
| Plugin requirement | None | None | Packlink PRO Shipping plugin | None |
| Per-code setup | Real tracking # + optional carrier code | Real tracking # + optional courier code | Shipment Reference only | Real FedEx tracking # |
