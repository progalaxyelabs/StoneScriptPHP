FROM node:18-alpine

WORKDIR /app

# Copy package files
COPY package*.json ./

# Install dependencies
RUN npm install

# Copy application files
COPY . .

# Expose Socket.IO port
EXPOSE 3001

# Start server
CMD ["npm", "start"]
