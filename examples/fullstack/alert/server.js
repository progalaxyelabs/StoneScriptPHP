const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const cors = require('cors');

const app = express();
app.use(cors());

const server = http.createServer(app);
const io = new Server(server, {
  cors: {
    origin: process.env.WWW_URL || "http://localhost:4200",
    methods: ["GET", "POST"]
  }
});

// Health check endpoint
app.get('/health', (req, res) => {
  res.json({ status: 'ok', service: 'alert', connections: io.engine.clientsCount });
});

// Socket.IO connection handling
io.on('connection', (socket) => {
  console.log(`[Alert] Client connected: ${socket.id}`);

  // Join user-specific room
  socket.on('join', (userId) => {
    socket.join(`user:${userId}`);
    console.log(`[Alert] User ${userId} joined their room`);
  });

  // Send notification to specific user
  socket.on('notify', (data) => {
    const { userId, notification } = data;
    io.to(`user:${userId}`).emit('notification', notification);
    console.log(`[Alert] Notification sent to user ${userId}:`, notification);
  });

  // Broadcast to all connected clients
  socket.on('broadcast', (message) => {
    io.emit('broadcast', message);
    console.log('[Alert] Broadcast sent:', message);
  });

  socket.on('disconnect', () => {
    console.log(`[Alert] Client disconnected: ${socket.id}`);
  });
});

const PORT = process.env.ALERT_PORT || 3001;

server.listen(PORT, () => {
  console.log(`[Alert] Socket.IO server running on port ${PORT}`);
  console.log(`[Alert] Allowed origins: ${process.env.WWW_URL || "http://localhost:4200"}`);
});
