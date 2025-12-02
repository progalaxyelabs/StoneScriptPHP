# SaaS Background Worker

Node.js worker for processing background jobs using Redis queues.

## Job Types

- **send-email** - Send transactional emails
- **process-usage** - Calculate monthly usage
- **cleanup-expired** - Remove expired trials
- **generate-invoices** - Create monthly invoices
- **send-notifications** - Push notifications
- **export-data** - Generate data exports

## Setup

```bash
cd worker

# Install dependencies
npm install

# Start worker
npm start
```

## Dependencies

```json
{
  "dependencies": {
    "bull": "^4.12.0",
    "nodemailer": "^6.9.0",
    "ioredis": "^5.3.0",
    "pg": "^8.11.0"
  }
}
```

## Job Structure

```javascript
// jobs/send-email.js
const nodemailer = require('nodemailer');

module.exports = async function sendEmail(job) {
  const { to, subject, html } = job.data;

  const transporter = nodemailer.createTransport({
    host: process.env.SMTP_HOST,
    port: process.env.SMTP_PORT,
    auth: {
      user: process.env.SMTP_USER,
      pass: process.env.SMTP_PASSWORD,
    },
  });

  await transporter.sendMail({
    from: process.env.FROM_EMAIL,
    to,
    subject,
    html,
  });

  console.log(`Email sent to ${to}`);
};
```

## Queue Job from API

```php
// In PHP backend
use Framework\Queue\JobQueue;

JobQueue::dispatch('send-email', [
    'to' => 'user@example.com',
    'subject' => 'Welcome!',
    'html' => '<h1>Welcome to our SaaS</h1>',
]);
```

## Monitoring

View queue status:

```bash
# Redis CLI
redis-cli
> LLEN bull:send-email:waiting
> LLEN bull:send-email:active
> LLEN bull:send-email:completed
```
