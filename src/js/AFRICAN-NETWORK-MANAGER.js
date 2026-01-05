/**
 * Network Resilience System for African Markets
 * Handles poor connectivity, timeouts, and connection quality
 */

class AfricanNetworkManager {
  constructor() {
    this.connectionQuality = 'unknown';
    this.adaptiveTimeouts = {
      'very_low': { init: 10000, upload: 60000, retry: 30000 },
      'low': { init: 5000, upload: 30000, retry: 15000 },
      'high': { init: 2000, upload: 15000, retry: 5000 }
    };
    this.circuitBreaker = {
      failures: 0,
      threshold: 5,
      timeout: 300000, // 5 minutes
      state: 'closed' // closed, open, half-open
    };
  }

  async detectNetworkQuality() {
    const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    
    if (connection) {
      const effectiveType = connection.effectiveType;
      const downlink = connection.downlink;
      const rtt = connection.rtt;
      
      // African-specific quality assessment
      if (effectiveType === 'slow-2g' || effectiveType === '2g' || downlink < 0.5) {
        this.connectionQuality = 'very_low';
      } else if (effectiveType === '3g' || downlink < 2 || rtt > 1000) {
        this.connectionQuality = 'low';
      } else {
        this.connectionQuality = 'high';
      }
    } else {
      // Fallback: measure actual performance
      this.connectionQuality = await this.measureConnectionSpeed();
    }
    
    return this.connectionQuality;
  }

  async measureConnectionSpeed() {
    try {
      const startTime = performance.now();
      const response = await fetch('/wp-content/plugins/starmus-audio-recorder/assets/test-1kb.txt', {
        cache: 'no-cache'
      });
      const endTime = performance.now();
      
      const duration = endTime - startTime;
      const bytes = 1024; // 1KB test file
      const bitsPerSecond = (bytes * 8) / (duration / 1000);
      
      if (bitsPerSecond < 50000) return 'very_low'; // < 50 Kbps
      if (bitsPerSecond < 500000) return 'low';     // < 500 Kbps
      return 'high';
    } catch (error) {
      return 'very_low'; // Assume worst case on error
    }
  }

  getTimeouts() {
    return this.adaptiveTimeouts[this.connectionQuality] || this.adaptiveTimeouts.very_low;
  }

  async executeWithCircuitBreaker(operation, context = '') {
    if (this.circuitBreaker.state === 'open') {
      const timeSinceOpen = Date.now() - this.circuitBreaker.openedAt;
      if (timeSinceOpen < this.circuitBreaker.timeout) {
        throw new Error(`Circuit breaker open for ${context}`);
      } else {
        this.circuitBreaker.state = 'half-open';
      }
    }

    try {
      const result = await operation();
      
      // Success - reset circuit breaker
      if (this.circuitBreaker.state === 'half-open') {
        this.circuitBreaker.state = 'closed';
        this.circuitBreaker.failures = 0;
      }
      
      return result;
    } catch (error) {
      this.circuitBreaker.failures++;
      
      if (this.circuitBreaker.failures >= this.circuitBreaker.threshold) {
        this.circuitBreaker.state = 'open';
        this.circuitBreaker.openedAt = Date.now();
        console.warn(`[Network] Circuit breaker opened for ${context}`);
      }
      
      throw error;
    }
  }

  // Progressive timeout for African networks
  createProgressiveTimeout(baseTimeout, attempt = 0) {
    const multiplier = Math.min(Math.pow(2, attempt), 8); // Cap at 8x
    const jitter = Math.random() * 0.1; // 10% jitter
    return baseTimeout * multiplier * (1 + jitter);
  }

  // Connection monitoring for African markets
  startConnectionMonitoring() {
    // Monitor connection changes
    window.addEventListener('online', () => {
      console.log('[Network] Connection restored');
      this.circuitBreaker.failures = Math.max(0, this.circuitBreaker.failures - 1);
    });

    window.addEventListener('offline', () => {
      console.log('[Network] Connection lost');
    });

    // Periodic quality assessment
    setInterval(async () => {
      if (navigator.onLine) {
        await this.detectNetworkQuality();
      }
    }, 60000); // Check every minute
  }
}