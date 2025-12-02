const { Server } = require('socket.io');

const PORT = process.env.PORT || 3001;
const CORS_ORIGIN = process.env.CORS_ORIGIN || 'http://localhost:4200';

const io = new Server(PORT, {
  cors: {
    origin: CORS_ORIGIN.split(','),
    methods: ['GET', 'POST'],
    credentials: true,
  },
});

console.log(`Socket.IO Alert Service started on port ${PORT}`);
console.log(`CORS enabled for: ${CORS_ORIGIN}`);

// Connection handling
io.on('connection', (socket) => {
  console.log(`Client connected: ${socket.id}`);

  // Send welcome notification
  socket.emit('notification', {
    type: 'info',
    message: 'Connected to alert service',
    timestamp: new Date().toISOString(),
  });

  // Listen for custom events
  socket.on('subscribe', (data) => {
    console.log(`Client ${socket.id} subscribed to:`, data);
    socket.join(data.channel || 'general');
  });

  socket.on('unsubscribe', (data) => {
    console.log(`Client ${socket.id} unsubscribed from:`, data);
    socket.leave(data.channel || 'general');
  });

  // Handle user-specific notifications
  socket.on('notify_user', (data) => {
    const { userId, notification } = data;
    io.to(`user-${userId}`).emit('notification', notification);
    console.log(`Notification sent to user ${userId}`);
  });

  // Broadcast to all clients in a channel
  socket.on('broadcast', (data) => {
    const { channel, message } = data;
    io.to(channel).emit('message', message);
    console.log(`Message broadcasted to channel: ${channel}`);
  });

  // Disconnect handling
  socket.on('disconnect', () => {
    console.log(`Client disconnected: ${socket.id}`);
  });
});

// Health check endpoint (for monitoring)
const http = require('http');
const server = http.createServer((req, res) => {
  if (req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ status: 'ok', connections: io.engine.clientsCount }));
  }
});

server.listen(PORT + 1, () => {
  console.log(`Health check endpoint available at http://localhost:${PORT + 1}/health`);
});

// Example: Send periodic broadcast (remove in production)
if (process.env.APP_ENV === 'development') {
  setInterval(() => {
    io.emit('notification', {
      type: 'info',
      message: 'Periodic notification from alert service',
      timestamp: new Date().toISOString(),
    });
  }, 60000); // Every minute
}
