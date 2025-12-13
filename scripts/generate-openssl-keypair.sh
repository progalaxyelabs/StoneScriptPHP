#!/bin/bash

# Script to generate an SSH key pair

KEY_TYPE="ed25519"       # You can change this to "ed25519" or "rsa"
KEY_SIZE="4096"     # For RSA, recommended size
KEY_FILENAME="./stone-script-php-jwt" # Customize the filename and path
NO_PASSPHRASE="y"    # Set to "y" for no passphrase, "n" to prompt

echo "Generating SSH key pair..."

if [ "$NO_PASSPHRASE" == "y" ]; then
  ssh-keygen -t "$KEY_TYPE" -b "$KEY_SIZE" -m pkcs8 -N "" -f "$KEY_FILENAME.pem"
  echo "SSH key pair generated without a passphrase."
else
  ssh-keygen -t "$KEY_TYPE" -b "$KEY_SIZE" -m pkcs8 -f "$KEY_FILENAME"
  echo "SSH key pair generation initiated. You will be prompted for a passphrase."
fi

mv "$KEY_FILENAME.pem.pub" "$KEY_FILENAME.pub"

echo "Private key saved to: $KEY_FILENAME.pem"
echo "Public key saved to: $KEY_FILENAME.pub"

# Optional: Set restrictive permissions on the private key
chmod 400 "$KEY_FILENAME.pem"

echo "Permissions set on the private key."

echo "Done!"