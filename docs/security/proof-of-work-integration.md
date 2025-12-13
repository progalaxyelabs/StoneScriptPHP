# Proof-of-Work Integration Guide

## What is Proof-of-Work?

A computational challenge that:
- ✅ **Invisible** to users (1-5 second delay)
- ✅ **Effective** against bots (makes automation expensive)
- ✅ **Free** - no third-party services
- ✅ **No CAPTCHA friction**

## How It Works

```
1. Client requests challenge from /api/challenge
   ← Server returns: { challenge: "abc123...", difficulty: 4 }

2. Client solves puzzle in JavaScript
   → Find nonce where SHA256(nonce + challenge) starts with "0000"
   → Takes ~1-5 seconds on average device

3. Client submits with solution
   POST /api/auth/register
   Header: X-POW-Solution: {"challenge":"abc123","nonce":12345,"difficulty":4}

4. Server verifies (instant)
   ✓ Correct solution → Process registration
   ✗ Invalid → Reject request
```

## Backend Setup

### 1. Add PoW Middleware

```php
use Framework\Routing\Middleware\ProofOfWorkMiddleware;
use App\Routes\Security\ChallengeRoute;

// Challenge endpoint
$router->get('/api/challenge', ChallengeRoute::class);

// Add PoW protection for sensitive routes
$router->use(new ProofOfWorkMiddleware([
    '/api/auth/register',
    '/api/contact/submit'
], difficulty: 4));

// Your routes
$router->post('/api/auth/register', RegisterRoute::class);
```

### 2. Configure Difficulty

```php
// Development (fast testing)
new ProofOfWorkMiddleware($routes, difficulty: 3);  // ~0.5 seconds

// Production (recommended)
new ProofOfWorkMiddleware($routes, difficulty: 4);  // ~3 seconds

// High security
new ProofOfWorkMiddleware($routes, difficulty: 5);  // ~15 seconds

// Maximum security
new ProofOfWorkMiddleware($routes, difficulty: 6);  // ~60 seconds
```

## Frontend Integration

### JavaScript Implementation

```javascript
/**
 * Proof-of-Work Solver
 * Solves computational challenges to prevent bot spam
 */
class ProofOfWorkSolver {
    /**
     * Request a new challenge from server
     */
    async requestChallenge(difficulty = 4) {
        const response = await fetch(`/api/challenge?difficulty=${difficulty}`);
        const data = await response.json();

        if (data.status !== 'success') {
            throw new Error(data.message || 'Failed to get challenge');
        }

        return data.data;
    }

    /**
     * Solve the challenge by finding valid nonce
     * This runs in the browser and takes 1-5 seconds
     */
    async solveChallenge(challenge, difficulty, onProgress = null) {
        const requiredPrefix = '0'.repeat(difficulty);
        let nonce = 0;
        const startTime = Date.now();

        while (true) {
            // Compute hash: SHA256(nonce + challenge)
            const hash = await this.sha256(nonce + challenge);

            // Check if hash starts with required zeros
            if (hash.startsWith(requiredPrefix)) {
                const solveTime = (Date.now() - startTime) / 1000;

                console.log(`✓ Challenge solved! Nonce: ${nonce}, Time: ${solveTime.toFixed(2)}s`);

                return {
                    challenge,
                    nonce,
                    difficulty,
                    hash,
                    solve_time: solveTime
                };
            }

            nonce++;

            // Report progress every 10000 attempts
            if (onProgress && nonce % 10000 === 0) {
                const elapsed = (Date.now() - startTime) / 1000;
                onProgress({
                    attempts: nonce,
                    elapsed,
                    rate: nonce / elapsed
                });
            }
        }
    }

    /**
     * SHA-256 hash function
     */
    async sha256(message) {
        const msgBuffer = new TextEncoder().encode(message);
        const hashBuffer = await crypto.subtle.digest('SHA-256', msgBuffer);
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
    }

    /**
     * All-in-one: Request challenge, solve it, return solution
     */
    async getSolution(difficulty = 4, onProgress = null) {
        // Get challenge
        console.log('Requesting challenge...');
        const challengeData = await this.requestChallenge(difficulty);

        console.log(`Solving challenge (difficulty ${difficulty})...`);
        console.log(`Estimated time: ${challengeData.estimated_time.avg_seconds} seconds`);

        // Solve challenge
        const solution = await this.solveChallenge(
            challengeData.challenge,
            challengeData.difficulty,
            onProgress
        );

        // Add expiry from challenge
        solution.expires_at = challengeData.expires_at;

        return solution;
    }
}

// Global instance
const powSolver = new ProofOfWorkSolver();

// Example: Registration with PoW
async function registerWithPoW(email, password, displayName) {
    try {
        // Show loading indicator
        showLoading('Verifying...');

        // Get PoW solution (this takes 1-5 seconds)
        const solution = await powSolver.getSolution(4, (progress) => {
            console.log(`Attempts: ${progress.attempts}, Rate: ${progress.rate.toFixed(0)}/sec`);
        });

        // Submit registration with solution
        const response = await fetch('/api/auth/register', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-POW-Solution': JSON.stringify(solution)
            },
            body: JSON.stringify({
                email,
                password,
                display_name: displayName
            })
        });

        const data = await response.json();

        hideLoading();

        if (data.status === 'success') {
            console.log('Registration successful!');
            return data;
        } else {
            throw new Error(data.message);
        }

    } catch (error) {
        hideLoading();
        console.error('Registration failed:', error);
        throw error;
    }
}

// Helper functions
function showLoading(message) {
    document.getElementById('loading').textContent = message;
    document.getElementById('loading').style.display = 'block';
}

function hideLoading() {
    document.getElementById('loading').style.display = 'none';
}
```

### React Hook

```jsx
import { useState, useCallback } from 'react';

/**
 * Custom hook for Proof-of-Work
 */
function useProofOfWork() {
    const [solving, setSolving] = useState(false);
    const [progress, setProgress] = useState(null);
    const [error, setError] = useState(null);

    const solver = useCallback(async (difficulty = 4) => {
        setSolving(true);
        setError(null);
        setProgress(null);

        try {
            const powSolver = new ProofOfWorkSolver();

            const solution = await powSolver.getSolution(difficulty, (prog) => {
                setProgress({
                    attempts: prog.attempts,
                    elapsed: prog.elapsed.toFixed(1),
                    rate: Math.round(prog.rate)
                });
            });

            setSolving(false);
            return solution;

        } catch (err) {
            setSolving(false);
            setError(err.message);
            throw err;
        }
    }, []);

    return { solve: solver, solving, progress, error };
}

// Usage in component
function RegisterForm() {
    const { solve, solving, progress } = useProofOfWork();
    const [formData, setFormData] = useState({ email: '', password: '', displayName: '' });

    const handleSubmit = async (e) => {
        e.preventDefault();

        try {
            // Solve PoW challenge
            const solution = await solve(4);

            // Submit with solution
            const response = await fetch('/api/auth/register', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-POW-Solution': JSON.stringify(solution)
                },
                body: JSON.stringify(formData)
            });

            const data = await response.json();

            if (data.status === 'success') {
                alert('Registration successful!');
            } else {
                alert(data.message);
            }

        } catch (err) {
            alert('Registration failed: ' + err.message);
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
            <input
                type="text"
                value={formData.displayName}
                onChange={(e) => setFormData({ ...formData, displayName: e.target.value })}
                placeholder="Display Name"
                required
            />

            <button type="submit" disabled={solving}>
                {solving ? `Verifying... ${progress?.attempts || 0} attempts` : 'Register'}
            </button>

            {progress && (
                <div className="progress">
                    Computing proof-of-work: {progress.rate}/sec
                </div>
            )}
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
    <input v-model="displayName" type="text" placeholder="Display Name" required />

    <button type="submit" :disabled="solving">
      {{ solving ? `Verifying... ${progress?.attempts || 0}` : 'Register' }}
    </button>

    <div v-if="progress" class="progress">
      Computing: {{ progress.rate }}/sec
    </div>
  </form>
</template>

<script>
import { ref } from 'vue';

export default {
  setup() {
    const email = ref('');
    const password = ref('');
    const displayName = ref('');
    const solving = ref(false);
    const progress = ref(null);

    const powSolver = new ProofOfWorkSolver();

    const handleSubmit = async () => {
      solving.value = true;
      progress.value = null;

      try {
        // Solve PoW
        const solution = await powSolver.getSolution(4, (prog) => {
          progress.value = {
            attempts: prog.attempts,
            rate: Math.round(prog.rate)
          };
        });

        // Submit
        const response = await fetch('/api/auth/register', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-POW-Solution': JSON.stringify(solution)
          },
          body: JSON.stringify({
            email: email.value,
            password: password.value,
            display_name: displayName.value
          })
        });

        const data = await response.json();

        if (data.status === 'success') {
          alert('Registration successful!');
        } else {
          alert(data.message);
        }

      } catch (err) {
        alert('Failed: ' + err.message);
      } finally {
        solving.value = false;
      }
    };

    return {
      email,
      password,
      displayName,
      solving,
      progress,
      handleSubmit
    };
  }
};
</script>
```

## Testing

### Test PoW Solver

```html
<!DOCTYPE html>
<html>
<head>
    <title>PoW Test</title>
</head>
<body>
    <h1>Proof-of-Work Test</h1>
    <button onclick="testPoW()">Test Difficulty 4</button>
    <div id="result"></div>

    <script>
        // Include ProofOfWorkSolver class here

        async function testPoW() {
            const solver = new ProofOfWorkSolver();
            const resultDiv = document.getElementById('result');

            resultDiv.innerHTML = 'Solving...';

            try {
                const solution = await solver.getSolution(4, (progress) => {
                    resultDiv.innerHTML = `
                        Attempts: ${progress.attempts}<br>
                        Rate: ${Math.round(progress.rate)}/sec<br>
                        Time: ${progress.elapsed.toFixed(1)}s
                    `;
                });

                resultDiv.innerHTML = `
                    <h3>✓ Solved!</h3>
                    Challenge: ${solution.challenge}<br>
                    Nonce: ${solution.nonce}<br>
                    Hash: ${solution.hash}<br>
                    Time: ${solution.solve_time.toFixed(2)}s
                `;

            } catch (err) {
                resultDiv.innerHTML = `<span style="color:red">Error: ${err.message}</span>`;
            }
        }
    </script>
</body>
</html>
```

## Performance Considerations

### Average Solve Times (on 2020-era devices)

| Difficulty | Desktop | Mobile | Estimate |
|-----------|---------|--------|----------|
| 3 | 0.1-1s | 0.5-2s | Fast |
| 4 | 1-5s | 2-10s | **Recommended** |
| 5 | 5-30s | 10-60s | High security |
| 6 | 30-120s | 60-300s | Maximum |

### Best Practices

✅ **DO:**
- Use difficulty 4 for production
- Show progress indicator to users
- Cache solutions temporarily (per form)
- Handle errors gracefully

❌ **DON'T:**
- Use difficulty > 5 for user-facing forms
- Solve challenges in loops (cache results)
- Block UI during solving (use async/await)
- Skip PoW for testing (use difficulty 3 instead)

## Error Handling

```javascript
async function registerWithErrorHandling(email, password) {
    try {
        const solution = await powSolver.getSolution(4);

        const response = await fetch('/api/auth/register', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-POW-Solution': JSON.stringify(solution)
            },
            body: JSON.stringify({ email, password })
        });

        const data = await response.json();

        if (data.error_code === 'POW_INVALID') {
            // Challenge expired or invalid - retry
            console.log('Challenge expired, retrying...');
            return await registerWithErrorHandling(email, password);
        }

        if (data.status !== 'success') {
            throw new Error(data.message);
        }

        return data;

    } catch (error) {
        console.error('Registration failed:', error);
        throw error;
    }
}
```

## Security Notes

- ✅ Solutions expire after 5 minutes
- ✅ Each solution is single-use
- ✅ Server verification is instant
- ✅ Cannot be bypassed without solving
- ⚠️ Can be parallelized (but still expensive)
- ⚠️ Doesn't prevent VPN/proxy rotation

## Cost Analysis for Attackers

Creating 1000 fake accounts with difficulty 4:

| Resource | Cost |
|----------|------|
| CPU time | 3000 seconds = 50 minutes |
| AWS EC2 (c5.large) | ~$0.50 |
| Total per 1000 accounts | **$0.50-1.00** |

**Combined with rate limiting + email verification:**
- Need 1000 unique IPs: $50-200
- Need 1000 valid emails: Hard to automate
- Total: **$200-500** for 1000 accounts

Most spam operations find this uneconomical.
