#!/usr/bin/env node
const fs = require('fs');
const crypto = require('crypto');
const path = require('path');

const files = process.argv.slice(2);
if (files.length === 0) {
  console.error('Usage: node build-starmus.cjs <file1> <file2> ...');
  process.exit(1);
}

console.log('🔨 Generating file hashes...');

files.forEach(file => {
  if (fs.existsSync(file)) {
    const content = fs.readFileSync(file);
    const hash = crypto.createHash('md5').update(content).digest('hex').substring(0, 8);
    console.log(`✅ ${path.basename(file)}: ${hash}`);
  } else {
    console.log(`❌ File not found: ${file}`);
  }
});

console.log('🎉 Hash generation complete!');