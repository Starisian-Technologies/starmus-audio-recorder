/**
 * Enhanced Offline Storage for African Markets
 * Addresses quota management, cleanup, and reliability
 */

class RobustOfflineStorage {
  constructor() {
    this.quotaThreshold = 0.8; // 80% quota usage threshold
    this.maxAge = 7 * 24 * 60 * 60 * 1000; // 7 days
    this.compressionEnabled = true;
  }

  async init() {
    // Check storage quota before initialization
    if ('storage' in navigator && 'estimate' in navigator.storage) {
      const estimate = await navigator.storage.estimate();
      const usageRatio = estimate.usage / estimate.quota;
      
      if (usageRatio > this.quotaThreshold) {
        await this.cleanup();
      }
    }
    
    // Initialize with error recovery
    try {
      await this.initDatabase();
    } catch (error) {
      console.warn('[Storage] IndexedDB failed, falling back to localStorage');
      return this.initLocalStorageFallback();
    }
  }

  async add(submission) {
    // Tier-based size limits for African markets
    const sizeLimits = {
      'A': 20 * 1024 * 1024, // 20MB
      'B': 10 * 1024 * 1024, // 10MB  
      'C': 5 * 1024 * 1024   // 5MB for poor devices
    };
    
    const tier = submission.metadata?.tier || 'C';
    const maxSize = sizeLimits[tier];
    
    if (submission.audioBlob.size > maxSize) {
      throw new Error(`File too large for ${tier} tier: ${submission.audioBlob.size} > ${maxSize}`);
    }
    
    // Compress audio for storage if enabled
    if (this.compressionEnabled && tier === 'C') {
      submission.audioBlob = await this.compressAudio(submission.audioBlob);
    }
    
    // Check quota before adding
    await this.ensureQuota(submission.audioBlob.size);
    
    return this.storeSubmission(submission);
  }

  async cleanup() {
    const submissions = await this.getAll();
    const now = Date.now();
    
    // Remove old submissions
    const toRemove = submissions.filter(s => 
      (now - s.timestamp) > this.maxAge || s.retryCount >= 15
    );
    
    for (const submission of toRemove) {
      await this.remove(submission.id);
    }
    
    console.log(`[Storage] Cleaned up ${toRemove.length} old submissions`);
  }

  async ensureQuota(requiredBytes) {
    if (!('storage' in navigator)) return;
    
    const estimate = await navigator.storage.estimate();
    const available = estimate.quota - estimate.usage;
    
    if (available < requiredBytes * 2) { // 2x buffer
      await this.cleanup();
      
      // Check again after cleanup
      const newEstimate = await navigator.storage.estimate();
      const newAvailable = newEstimate.quota - newEstimate.usage;
      
      if (newAvailable < requiredBytes) {
        throw new Error('Insufficient storage quota');
      }
    }
  }

  async compressAudio(blob) {
    // Simple compression for African markets
    // In production, use proper audio compression
    return new Blob([blob], { type: blob.type });
  }

  // localStorage fallback for when IndexedDB fails
  initLocalStorageFallback() {
    this.useLocalStorage = true;
    console.warn('[Storage] Using localStorage fallback - limited capacity');
  }
}