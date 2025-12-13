# hCaptcha Integration Guide

## Overview

hCaptcha is a privacy-focused CAPTCHA service that protects your forms from bot spam while respecting user privacy.

**Why hCaptcha over reCAPTCHA:**
- ✅ **Privacy-focused** - GDPR compliant, doesn't track users
- ✅ **Better accessibility** - Supports screen readers
- ✅ **Pays websites** - Proof of Human rewards
- ✅ **Free tier** - 1M requests/month
- ✅ **Open source friendly**
- ✅ **No Google tracking**

## Quick Start

### 1. Get hCaptcha Keys

1. Sign up at [https://www.hcaptcha.com/](https://www.hcaptcha.com/)
2. Create a new site
3. Copy your **Site Key** and **Secret Key**

### 2. Add to .env

```env
# hCaptcha (leave empty to disable)
HCAPTCHA_SITE_KEY=10000000-ffff-ffff-ffff-000000000001
HCAPTCHA_SECRET_KEY=0x0000000000000000000000000000000000000000
```

**That's it!** If these keys are set, hCaptcha is automatically enabled for all protected routes.

If keys are **NOT** set, hCaptcha middleware will be **automatically disabled**.

### 3. Add to Frontend

```html
<!DOCTYPE html>
<html>
<head>
    <title>Registration</title>
    <!-- Add hCaptcha script -->
    <script src="https://js.hcaptcha.com/1/api.js" async defer></script>
</head>
<body>
    <form id="registerForm">
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password" required>

        <!-- Add hCaptcha widget -->
        <div class="h-captcha" data-sitekey="YOUR_SITE_KEY"></div>

        <button type="submit">Register</button>
    </form>

    <script>
        document.getElementById('registerForm').addEventListener('submit', async (e) => {
            e.preventDefault();

            // Get hCaptcha token
            const hcaptchaResponse = document.querySelector('[name="h-captcha-response"]').value;

            if (!hcaptchaResponse) {
                alert('Please complete the CAPTCHA');
                return;
            }

            // Submit form
            const response = await fetch('/api/auth/register', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    email: document.querySelector('[name="email"]').value,
                    password: document.querySelector('[name="password"]').value,
                    'h-captcha-response': hcaptchaResponse
                })
            });

            const data = await response.json();

            if (data.status === 'success') {
                alert('Registration successful!');
            } else {
                alert(data.message);
            }
        });
    </script>
</body>
</html>
```

## Backend Configuration

The framework automatically protects these routes when hCaptcha is enabled:

```php
// In email-password.php.template
$router->use(new HCaptchaMiddleware([
    '/api/auth/register',           // Registration
    '/api/auth/login',              // Login
    '/api/auth/resend-verification', // Resend verification email
    '/api/auth/password-reset',     // Request password reset
    '/api/auth/password-reset/confirm' // Confirm password reset
]));
```

### Custom Protection

```php
// Protect additional routes
$router->use(new HCaptchaMiddleware([
    '/api/contact/submit',
    '/api/feedback/create',
    '/api/support/ticket'
]));
```

## Frontend Integration Examples

### Vanilla JavaScript

```javascript
class HCaptchaManager {
    constructor(siteKey) {
        this.siteKey = siteKey;
        this.widgetId = null;
    }

    /**
     * Initialize hCaptcha widget
     */
    init(containerId) {
        if (!window.hcaptcha) {
            console.error('hCaptcha script not loaded');
            return;
        }

        this.widgetId = hcaptcha.render(containerId, {
            sitekey: this.siteKey,
            theme: 'light', // or 'dark'
            size: 'normal', // or 'compact'
            callback: this.onSuccess.bind(this),
            'expired-callback': this.onExpire.bind(this),
            'error-callback': this.onError.bind(this)
        });
    }

    /**
     * Get current token
     */
    getToken() {
        if (!window.hcaptcha || this.widgetId === null) {
            return null;
        }
        return hcaptcha.getResponse(this.widgetId);
    }

    /**
     * Reset widget
     */
    reset() {
        if (window.hcaptcha && this.widgetId !== null) {
            hcaptcha.reset(this.widgetId);
        }
    }

    onSuccess(token) {
        console.log('hCaptcha verified:', token);
    }

    onExpire() {
        console.log('hCaptcha expired');
    }

    onError(error) {
        console.error('hCaptcha error:', error);
    }
}

// Usage
const hcaptcha = new HCaptchaManager('YOUR_SITE_KEY');
hcaptcha.init('hcaptcha-container');

// On form submit
async function submitForm() {
    const token = hcaptcha.getToken();

    if (!token) {
        alert('Please complete the CAPTCHA');
        return;
    }

    const response = await fetch('/api/auth/register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            email: 'user@example.com',
            password: 'password123',
            'h-captcha-response': token
        })
    });

    // Reset CAPTCHA after use
    hcaptcha.reset();
}
```

### React Component

```jsx
import { useEffect, useState } from 'react';

function RegisterForm() {
    const [hcaptchaToken, setHcaptchaToken] = useState(null);
    const [formData, setFormData] = useState({ email: '', password: '' });

    useEffect(() => {
        // Load hCaptcha script
        const script = document.createElement('script');
        script.src = 'https://js.hcaptcha.com/1/api.js';
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);

        // Setup callback
        window.hcaptchaCallback = (token) => {
            setHcaptchaToken(token);
        };

        window.hcaptchaExpired = () => {
            setHcaptchaToken(null);
        };

        return () => {
            document.head.removeChild(script);
        };
    }, []);

    const handleSubmit = async (e) => {
        e.preventDefault();

        if (!hcaptchaToken) {
            alert('Please complete the CAPTCHA');
            return;
        }

        try {
            const response = await fetch('/api/auth/register', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    ...formData,
                    'h-captcha-response': hcaptchaToken
                })
            });

            const data = await response.json();

            if (data.status === 'success') {
                alert('Registration successful!');
            } else {
                alert(data.message);
                // Reset hCaptcha
                window.hcaptcha.reset();
                setHcaptchaToken(null);
            }
        } catch (error) {
            alert('Registration failed: ' + error.message);
        }
    };

    return (
        <form onSubmit={handleSubmit}>
            <input
                type="email"
                value={formData.email}
                onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                placeholder="Email"
                required
            />
            <input
                type="password"
                value={formData.password}
                onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                placeholder="Password"
                required
            />

            <div
                className="h-captcha"
                data-sitekey={process.env.REACT_APP_HCAPTCHA_SITE_KEY}
                data-callback="hcaptchaCallback"
                data-expired-callback="hcaptchaExpired"
            ></div>

            <button type="submit" disabled={!hcaptchaToken}>
                Register
            </button>
        </form>
    );
}
```

### Vue Component

```vue
<template>
  <form @submit.prevent="handleSubmit">
    <input v-model="email" type="email" placeholder="Email" required />
    <input v-model="password" type="password" placeholder="Password" required />

    <div
      class="h-captcha"
      :data-sitekey="siteKey"
      data-callback="onHCaptchaSuccess"
      data-expired-callback="onHCaptchaExpired"
    ></div>

    <button type="submit" :disabled="!hcaptchaToken">Register</button>
  </form>
</template>

<script>
export default {
  data() {
    return {
      email: '',
      password: '',
      hcaptchaToken: null,
      siteKey: process.env.VUE_APP_HCAPTCHA_SITE_KEY
    };
  },
  mounted() {
    // Load hCaptcha script
    const script = document.createElement('script');
    script.src = 'https://js.hcaptcha.com/1/api.js';
    script.async = true;
    script.defer = true;
    document.head.appendChild(script);

    // Setup callbacks
    window.onHCaptchaSuccess = (token) => {
      this.hcaptchaToken = token;
    };

    window.onHCaptchaExpired = () => {
      this.hcaptchaToken = null;
    };
  },
  methods: {
    async handleSubmit() {
      if (!this.hcaptchaToken) {
        alert('Please complete the CAPTCHA');
        return;
      }

      try {
        const response = await fetch('/api/auth/register', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            email: this.email,
            password: this.password,
            'h-captcha-response': this.hcaptchaToken
          })
        });

        const data = await response.json();

        if (data.status === 'success') {
          alert('Registration successful!');
        } else {
          alert(data.message);
          window.hcaptcha.reset();
          this.hcaptchaToken = null;
        }
      } catch (error) {
        alert('Failed: ' + error.message);
      }
    }
  }
};
</script>
```

## Configuration Options

### Widget Options

```html
<div class="h-captcha"
     data-sitekey="YOUR_SITE_KEY"
     data-theme="dark"           <!-- 'light' or 'dark' -->
     data-size="compact"         <!-- 'normal', 'compact', or 'invisible' -->
     data-callback="onSuccess"   <!-- Success callback -->
     data-expired-callback="onExpire"
     data-error-callback="onError"
     data-language="en"          <!-- Language code -->
></div>
```

### Invisible hCaptcha

```html
<form id="myForm">
    <input type="email" name="email">

    <!-- Invisible hCaptcha -->
    <div class="h-captcha"
         data-sitekey="YOUR_SITE_KEY"
         data-size="invisible"
         data-callback="onSubmit"
    ></div>

    <button type="submit">Submit</button>
</form>

<script>
function onSubmit(token) {
    // Form will be submitted after CAPTCHA is solved
    document.getElementById('myForm').submit();
}
</script>
```

## Error Handling

### Backend Errors

```json
{
  "status": "error",
  "message": "CAPTCHA verification failed",
  "data": {
    "error_code": "CAPTCHA_INVALID",
    "message": "Please complete the CAPTCHA verification again"
  },
  "http_code": 403
}
```

### Frontend Error Handling

```javascript
async function submitWithErrorHandling() {
    const token = hcaptcha.getToken();

    if (!token) {
        alert('Please complete the CAPTCHA');
        return;
    }

    try {
        const response = await fetch('/api/auth/register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                email: 'user@example.com',
                password: 'pass123',
                'h-captcha-response': token
            })
        });

        const data = await response.json();

        if (data.error_code === 'CAPTCHA_INVALID') {
            alert('CAPTCHA verification failed. Please try again.');
            hcaptcha.reset();
            return;
        }

        if (data.error_code === 'CAPTCHA_REQUIRED') {
            alert('CAPTCHA is required for this action');
            return;
        }

        if (data.status === 'success') {
            alert('Success!');
        } else {
            alert(data.message);
        }

    } catch (error) {
        alert('Request failed: ' + error.message);
    }
}
```

## Testing

### Development Mode

For local development without real hCaptcha:

```env
# Leave empty to disable hCaptcha
HCAPTCHA_SITE_KEY=
HCAPTCHA_SECRET_KEY=
```

### Test Keys (From hCaptcha)

```env
# Use these for testing (always pass)
HCAPTCHA_SITE_KEY=10000000-ffff-ffff-ffff-000000000001
HCAPTCHA_SECRET_KEY=0x0000000000000000000000000000000000000000
```

## Production Checklist

- [ ] Sign up for hCaptcha account
- [ ] Create production site in hCaptcha dashboard
- [ ] Add your domain to allowed domains
- [ ] Copy real keys to production `.env`
- [ ] Test registration form
- [ ] Monitor hCaptcha dashboard for usage
- [ ] Set up alerts for high failure rates

## Troubleshooting

### "CAPTCHA verification failed"

**Cause:** Invalid or expired token

**Solution:**
- Check site key is correct
- Ensure token is being sent in request
- Token expires after 2 minutes - reset and try again

### "hCaptcha widget not showing"

**Cause:** Script not loaded or site key invalid

**Solution:**
```html
<!-- Make sure script is loaded -->
<script src="https://js.hcaptcha.com/1/api.js" async defer></script>

<!-- Check browser console for errors -->
<!-- Verify site key is correct -->
```

### "Same token used multiple times"

**Cause:** Token reuse (tokens are single-use)

**Solution:**
```javascript
// Reset hCaptcha after each submission
hcaptcha.reset();
```

### Development: "Want to test without CAPTCHA"

**Solution:**
```env
# Simply remove or comment out the keys in .env
# HCAPTCHA_SITE_KEY=
# HCAPTCHA_SECRET_KEY=

# Middleware will automatically disable
```

## Security Best Practices

✅ **DO:**
- Always validate on server-side (never trust client)
- Reset widget after failed submissions
- Use HTTPS in production
- Monitor hCaptcha dashboard for abuse
- Set appropriate difficulty level

❌ **DON'T:**
- Skip server-side verification
- Reuse tokens
- Expose secret key in frontend
- Disable CAPTCHA in production without alternative protection

## Cost & Limits

**Free Tier:**
- 1,000,000 requests/month
- No credit card required
- Full features

**Enterprise:**
- Unlimited requests
- Custom branding
- Priority support

## Additional Resources

- [hCaptcha Documentation](https://docs.hcaptcha.com/)
- [hCaptcha Dashboard](https://dashboard.hcaptcha.com/)
- [hCaptcha API Reference](https://docs.hcaptcha.com/api)
