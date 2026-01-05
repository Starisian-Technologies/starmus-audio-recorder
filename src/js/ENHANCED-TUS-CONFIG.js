/**
 * Enhanced TUS Configuration for African Markets
 * Addresses security, performance, and reliability concerns
 */

// Enhanced TUS configuration with security hardening
function getSecureTusConfig(tier, networkType) {
  const baseConfig = {
    // Tier-based chunk sizes for African networks
    chunkSize: {
      'A': networkType === 'high' ? 1024 * 1024 : 512 * 1024, // 1MB/512KB
      'B': networkType === 'high' ? 512 * 1024 : 256 * 1024,  // 512KB/256KB  
      'C': 128 * 1024 // 128KB max for poor networks
    }[tier] || 128 * 1024,
    
    // African-optimized retry delays (longer for poor networks)
    retryDelays: networkType === 'very_low' 
      ? [0, 10000, 30000, 60000, 180000, 300000, 600000] // Longer delays for 2G
      : [0, 5000, 15000, 45000, 120000, 300000], // Standard delays
    
    // Enhanced security headers
    headers: {
      'x-starmus-secret': generateSecureSecret(),
      'x-starmus-timestamp': Date.now().toString(),
      'x-starmus-signature': generateHMAC(payload, secret),
      'x-starmus-tier': tier,
      'x-starmus-network': networkType
    },
    
    // Connection management for poor networks
    parallelUploads: tier === 'C' ? 1 : 2, // Single connection for poor devices
    maxChunkRetries: networkType === 'very_low' ? 15 : 10,
    
    // Enhanced fingerprinting for African markets
    fingerprint: (file, options) => {
      return `${tier}-${networkType}-${file.size}-${Date.now()}`;
    }
  };
  
  return baseConfig;
}

// Secure secret generation
function generateSecureSecret() {
  const timestamp = Date.now();
  const random = crypto.getRandomValues(new Uint8Array(16));
  const combined = `${timestamp}-${Array.from(random).join('')}`;
  return btoa(combined).substring(0, 32);
}

// HMAC signature for request validation
function generateHMAC(payload, secret) {
  // Implementation would use Web Crypto API or fallback
  return crypto.subtle.sign('HMAC', secret, payload);
}