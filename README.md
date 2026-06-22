# WooLens AI

**From image to words — instantly.**

WooLens AI analyzes your WooCommerce product image and automatically writes the title and description for you. No typing. No ChatGPT tabs. Just click and done.

---

## How it works

1. Set a featured image on your WooCommerce product
2. Click **Generate title** or **Generate description**
3. WooLens AI sends the image to Google Gemini
4. AI analyzes the image and writes the content
5. Fields are filled automatically — edit if needed, then save

---

## Supported AI Provider

| Provider | Free Model | Pro Models |
|---|---|---|
| Google Gemini | gemini-3.1-flash-lite | gemini-3.1-flash-lite, gemini-2.5-pro, gemini-2.5-flash, gemini-2.0-flash, gemini-1.5-flash, gemini-1.5-pro |

---

## Free vs Pro

| Feature | Free | Pro |
|---|---|---|
| Generate title | Yes | Yes |
| Generate description | Yes | Yes |
| Daily limit | 10/day | Unlimited |
| Output language | English only | Urdu, Arabic, Hindi, French, Spanish & more |
| AI models | gemini-3.1-flash-lite only | 6 Gemini models |
| Custom providers (Groq, Mistral etc.) | No | Coming soon |
| Bulk generation | No | Coming soon |
| Priority support | No | Yes |

---

## Installation

1. Upload `woolens-ai` folder to `/wp-content/plugins/`
2. Activate in **Plugins → Installed Plugins**
3. Go to **WooLens AI** in the sidebar → enter your Gemini API key
4. Open any product, set a featured image, click **Generate title** or **Generate description**

---

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 8.0+
- Google AI Studio API key (free at aistudio.google.com)

---

## Security

- API keys stored server-side in WordPress options (never exposed to browser)
- All AJAX requests protected by WordPress nonces
- Capability check on every request (`edit_products`)
- Image host validation (only your own site images)
- Image MIME type validation
- Input sanitization throughout

---

## File Structure

```
woolens-ai/
├── woolens-ai.php                  ← Plugin bootstrap
├── includes/
│   ├── class-rate-limiter.php      ← Daily usage tracking
│   └── class-ai-client.php         ← Gemini API integration
├── admin/
│   ├── class-settings-page.php     ← WooLens AI settings page
│   └── class-product-editor.php    ← Product page buttons + AJAX
└── assets/
    └── css/admin.css
```

---

## Changelog

### 1.2.0
- Switched default model to gemini-3.1-flash-lite (faster, more cost-efficient)
- Fixed: image change not reflected until product was saved
- Removed emoji icons from admin UI for native WordPress feel

### 1.1.0
- Per-user daily rate limiting
- Testing mode for admins (100 requests/day)
- Usage progress bar on product editor
- Multilingual output (Pro)

### 1.0.0
- Initial release
- Google Gemini vision API integration
- Generate title, description, or both from product image
- Free (10/day) and Pro (unlimited) plans
- Secure image validation
