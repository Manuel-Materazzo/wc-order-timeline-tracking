# WC Order Timeline Tracking

**WC Order Timeline Tracking** is a feature-packed WooCommerce extension that provides personalized, step-by-step order tracking timeline management. It features custom timeline milestones, status presets, administrative timeline managers, and automatic real-time sync with **17TRACK API** and **Packlink PRO API**.

---

## 🚀 Key Features

* **Custom Order Timelines**: Easily manage, add, edit, and reorder step-by-step tracking events for WooCommerce orders.
* **Status & Preset Management**: Define and save timeline step templates/presets for fast application to orders.
* **Automated 17TRACK Sync**: Auto-fetch live shipping carrier updates using the 17TRACK API background cron job.
* **Packlink PRO Sync**: Auto-fetch tracking events directly from your Packlink PRO shipment references (requires the Packlink PRO Shipping plugin).
* **Carrier Auto-Detection**: Automatically detect courier services based on the tracking number (17TRACK provider).
* **Frontend Tracking Shortcode**: Display a sleek, modern, mobile-responsive tracking timeline to customers using `[wc_order_timeline_tracking]`.
* **Refund Column Integration**: Adds timeline visibility directly inside WooCommerce admin order lists.

---

## 📋 Requirements

* **WordPress**: 6.0 or higher
* **WooCommerce**: 7.0 or higher (Tested up to 9.9)
* **PHP**: 8.0 or higher
* **17TRACK Account & API Key** *(Optional, for 17TRACK automated carrier tracking)*
* **Packlink PRO Account, API Key & the [Packlink PRO Shipping](https://wordpress.org/plugins/packlink-pro-shipping/) plugin** *(Optional, for Packlink automated tracking)*

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

## 📦 Packlink PRO Integration & Auto-Sync

Packlink PRO tracking is available **only when the [Packlink PRO Shipping for WooCommerce](https://wordpress.org/plugins/packlink-pro-shipping/) plugin is installed and active**. Unlike 17TRACK, Packlink tracks by its own **Shipment Reference** (e.g. `ESXXXXXXXXXXXXXX`) — no carrier code is required.

1. Ensure the **Packlink PRO Shipping** plugin is installed and active.
2. Navigate to **Timeline Tracking > ⚙ Settings**.
3. Input your **Packlink PRO API Key** (obtain it from your Packlink PRO dashboard under **Settings → Packlink PRO API**).
4. Save settings.
5. On each tracking code's detail page, select **Packlink PRO** as the provider and enter the Packlink **Shipment Reference** as the tracking reference.
6. The same background cron job will auto-fetch updates from Packlink and add them as timeline steps.

### Key differences vs. 17TRACK

| Feature | 17TRACK | Packlink PRO |
|---|---|---|
| Tracking identifier | Carrier tracking number | Packlink Shipment Reference |
| Carrier detection | ✓ Auto-detect | ✗ Not applicable |
| Plugin requirement | None | Packlink PRO Shipping plugin |
| Per-code setup | Real tracking # + optional carrier code | Shipment Reference only |
