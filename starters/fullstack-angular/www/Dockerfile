FROM node:18-alpine

WORKDIR /app

# Copy package files
COPY package*.json ./

# Install dependencies
RUN npm install

# Copy application files
COPY . .

# Expose Angular dev server port
EXPOSE 4200

# Start dev server
CMD ["npm", "start", "--", "--host", "0.0.0.0", "--poll=2000"]
