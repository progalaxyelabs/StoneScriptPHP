# Bot Protection & API Security Strategy

## The Reality Check

**You CANNOT 100% prevent automated registrations.**

Any API endpoint that accepts public requests can be scripted. Headers (Origin, User-Agent, Referer) can all be spoofed with curl.

**The goal is to make automation expensive/difficult enough that it's not worth it.**

## Multi-Layer Defense Strategy

### Layer 1: Rate Limiting (Already Implemented ✅)

**What it does:** Limits requests per IP/fingerprint
**Effectiveness:** 🟡 Moderate - Stops basic scripts
**Bypass:** Easy with VPN/proxy rotation
**Cost to bypass:** Low ($5-20/month for rotating proxies)

```php
// In production, use Redis/database for persistence
$router->use(new RateLimitMiddleware(
    maxRequests: 10,
    windowSeconds: 3600  // 10 registrations per hour per IP
));
```

### Layer 2: CSRF Tokens (Already Implemented ✅)

**What it does:** Requires token from GET request before POST
**Effectiveness:** 🟡 Moderate - Stops lazy bots
**Bypass:** Easy (2-step script: GET token → POST with token)
**Cost to bypass:** None (just adds one line of code)

### Layer 3: Proof-of-Work Challenge 🔥 **RECOMMENDED**

**What it does:** Requires client to solve computational puzzle
**Effectiveness:** 🟢 High - Makes automation expensive
**Bypass:** Possible but computationally expensive
**Cost to bypass:** High (requires significant CPU/time per registration)

This is the **most effective** non-CAPTCHA solution.

### Layer 4: hCaptcha/reCAPTCHA

**What it does:** Human verification via CAPTCHA
**Effectiveness:** 🟢🟢 Very High - Best human verification
**Bypass:** Very difficult (requires CAPTCHA solving services)
**Cost to bypass:** High ($1-3 per 1000 solves)

### Layer 5: Email Verification (Already Implemented ✅)

**What it does:** Requires email click-through
**Effectiveness:** 🟢 High - Prevents throwaway accounts
**Bypass:** Moderate (requires real email or temp email service)
**Cost to bypass:** Medium (temp email services can be blocked)

### Layer 6: Progressive Trust System

**What it does:** New accounts have limited capabilities
**Effectiveness:** 🟢 High - Limits abuse impact
**Bypass:** Cannot bypass, but can create many limited accounts

## Recommended Implementation (Based on Your Needs)

### Option A: Moderate Security (Good for Most Apps)
```
Rate Limiting + CSRF + Email Verification
```
- Stops 90% of automated attempts
- No user friction
- Free to implement

### Option B: High Security (E-commerce, Finance)
```
Rate Limiting + Proof-of-Work + Email Verification + hCaptcha (fallback)
```
- Stops 99%+ of automated attempts
- Minimal friction (PoW is invisible to users)
- hCaptcha only shown if PoW fails repeatedly

### Option C: Maximum Security (Critical Applications)
```
All Layers + Manual Review + KYC
```
- Near 100% protection
- High friction - only for critical apps

## Why Origin Header is NOT Reliable

```bash
# Anyone can spoof Origin header
curl -X POST https://yourapi.com/api/auth/register \
  -H "Origin: https://your-trusted-domain.com" \
  -H "Content-Type: application/json" \
  -d '{"email":"bot@example.com","password":"123"}'
```

**Headers are just strings.** Never trust client-provided headers for security.

## The Only TRUE Solutions

### 1. Proof-of-Work (Client-Side Computational Challenge)

**How it works:**
1. Server generates challenge: "Find hash where SHA256(nonce + challenge) starts with '0000'"
2. Client must brute-force find valid nonce (takes ~1-5 seconds on average device)
3. Server verifies solution (instant)

**Why it works:**
- Legitimate users: Barely notice (1-5 second delay)
- Bots: Each registration costs significant CPU time
- Creating 1000 accounts = Hours of computation

**Implementation:**
```javascript
// Client-side (JavaScript)
async function solveChallenge(challenge, difficulty) {
    let nonce = 0;
    const prefix = '0'.repeat(difficulty);

    while (true) {
        const hash = await sha256(nonce + challenge);
        if (hash.startsWith(prefix)) {
            return { nonce, hash };
        }
        nonce++;

        // Show progress every 10000 attempts
        if (nonce % 10000 === 0) {
            console.log(`Solving challenge... ${nonce} attempts`);
        }
    }
}

// Use before registration
const challenge = await fetch('/api/challenge').then(r => r.json());
const solution = await solveChallenge(challenge.data, 4); // difficulty = 4

await fetch('/api/auth/register', {
    method: 'POST',
    headers: {
        'X-Challenge-Solution': JSON.stringify(solution)
    },
    body: JSON.stringify({ email, password })
});
```

```php
// Server-side verification
$challenge = $_SESSION['challenge'];
$solution = json_decode($_SERVER['HTTP_X_CHALLENGE_SOLUTION'], true);

$hash = hash('sha256', $solution['nonce'] . $challenge);

if ($hash !== $solution['hash'] || !str_starts_with($hash, str_repeat('0', 4))) {
    return new ApiResponse('error', 'Invalid proof-of-work solution', null, 403);
}
```

### 2. hCaptcha (Human Verification)

**Free tier:** 1M requests/month
**Privacy-focused** alternative to reCAPTCHA
**Open source** client libraries

```html
<!-- Add to registration form -->
<div class="h-captcha" data-sitekey="YOUR_SITE_KEY"></div>
<script src="https://js.hcaptcha.com/1/api.js" async defer></script>
```

```php
// Server-side verification
$token = $_POST['h-captcha-response'];

$response = file_get_contents('https://hcaptcha.com/siteverify', false, stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query([
            'secret' => getenv('HCAPTCHA_SECRET'),
            'response' => $token
        ])
    ]
]));

$result = json_decode($response, true);

if (!$result['success']) {
    return new ApiResponse('error', 'CAPTCHA verification failed', null, 403);
}
```

### 3. Device Fingerprinting + Behavioral Analysis

**What it detects:**
- Browser automation tools (Selenium, Puppeteer)
- Headless browsers
- Abnormal mouse/keyboard patterns
- Screen resolution anomalies
- Missing browser features

**JavaScript libraries:**
- FingerprintJS (open source)
- Botd (bot detection)

```javascript
import FingerprintJS from '@fingerprintjs/fingerprintjs';

const fp = await FingerprintJS.load();
const result = await fp.get();

// Send fingerprint with registration
const fingerprint = result.visitorId;
```

**Server stores fingerprint and monitors:**
- Multiple accounts from same fingerprint → Suspicious
- Fingerprint changes frequently → VPN/automation
- Pattern recognition over time

## Practical Recommendation

### Implement This 3-Layer Approach:

**Layer 1: Rate Limiting** (Already done ✅)
- 3 registrations per IP per hour
- 10 registrations per IP per day

**Layer 2: Proof-of-Work Challenge** (Implement this!)
- Difficulty 4 (~1-5 seconds to solve)
- Invisible to users
- Makes mass registration expensive

**Layer 3: Email Verification** (Already done ✅)
- Block disposable email domains
- Require click-through verification

**Optional Layer 4: hCaptcha (Fallback)**
- Only show if PoW fails 3+ times
- Or if suspicious activity detected

### This combination:
✅ Stops 99%+ of bots
✅ Zero friction for legitimate users (PoW is transparent)
✅ Free to implement
✅ No third-party dependencies (except optional hCaptcha)

## Advanced: App Attestation (Mobile Apps Only)

For mobile apps, use platform-specific attestation:

**iOS: App Attest API**
```swift
let challenge = Data() // from server
let attestation = try await DCAppAttestService.shared.attest(challenge)
```

**Android: Play Integrity API**
```kotlin
val integrityManager = IntegrityManagerFactory.create(context)
val request = IntegrityTokenRequest.builder()
    .setCloudProjectNumber(PROJECT_NUMBER)
    .build()
```

These APIs prove the request comes from your signed app, not curl.

## Monitoring & Detection

Even with all protections, monitor for patterns:

```sql
-- Find IPs with multiple accounts
SELECT ip, COUNT(*) as accounts
FROM users
WHERE created_at > NOW() - INTERVAL '24 hours'
GROUP BY ip
HAVING COUNT(*) > 5;

-- Find suspicious email patterns
SELECT email FROM users WHERE email LIKE '%+%' OR email LIKE '%temp%';

-- Find burst registrations
SELECT DATE(created_at) as date, COUNT(*) as count
FROM users
GROUP BY date
HAVING count > 100;
```

**Set up alerts** for unusual activity:
- 50+ registrations in 1 hour → Alert
- Same IP creates 10+ accounts → Auto-blacklist
- Email domain has 20+ accounts → Flag domain

## Conclusion

**You cannot prevent API abuse 100%, but you can make it economically unviable.**

**Best practical approach:**
1. ✅ Rate limiting (implemented)
2. ✅ CSRF tokens (implemented)
3. 🔥 **Proof-of-Work challenge (IMPLEMENT THIS!)**
4. ✅ Email verification (implemented)
5. ⚠️  hCaptcha (optional fallback)
6. 📊 Monitoring & alerts

This makes creating 1000 fake accounts require:
- 1000 unique IPs (expensive)
- 1-5 CPU hours of computation (proof-of-work)
- 1000 valid email addresses (hard to automate)
- Bypassing hCaptcha if triggered ($1000+)

**Total cost: $200-500 for 1000 accounts**

For most attackers, this is not worth it. Mission accomplished.
