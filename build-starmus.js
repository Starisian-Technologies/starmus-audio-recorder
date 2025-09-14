#!/usr/bin/env node
// build-starmus.js - Enhanced build script for Starmus Audio Recorder

import { readFileSync, writeFileSync, existsSync, statSync } from 'fs';
import { createHash } from 'crypto';
import { resolve, extname, basename } from 'path';

// --- Configuration ---

const HEADER_TAG = '// ==== starmus-audio-recorder-module.js ====';
const SUPPORTED_EXTENSIONS = ['.js', '.mjs', '.ts', '.css', '.scss', '.sass'];
const HASH_REGEX = /\/\/ Build Hash \(SHA-1\):[^\n]*\n\/\/ Build Hash \(SHA-256\):[^\n]*/g;


function stableHashBytes(content) {
	// Normalize line endings and remove comments for stable hashing
	return Buffer.from(
		content
			.replace(/\r\n/g, '\n')
			.replace(/^\/\/.*$/gm, '')
			.replace(/\/\*[\s\S]*?\*\//g, '')
			.replace(/\n\s*\n/g, '\n')
			.trim(),
		'utf8'
	);
}

function hash(content) {
	const b = stableHashBytes(content);
	return {
		sha1:   createHash('sha1').update(b).digest('hex'),
		sha256: createHash('sha256').update(b).digest('hex'),
	};
}

function applyHeaderAndProp(content, sha1, sha256, filePath) {
	const timestamp = new Date().toISOString();
	const ext = extname(filePath).toLowerCase();
	const isCSS = ['.css', '.scss', '.sass'].includes(ext);
	
	const header = isCSS 
		? `/* Build Hash (SHA-1): ${sha1} */\n/* Build Hash (SHA-256): ${sha256} */\n/* Build Time: ${timestamp} */\n`
		: `${HEADER_TAG}\n// Build Hash (SHA-1):   ${sha1}\n// Build Hash (SHA-256): ${sha256}\n// Build Time: ${timestamp}\n`;
	
	let out = content;
	
	if (isCSS) {
		// For CSS files, replace existing header or add new one
		const existingHeaderRegex = /\/\* Build Hash[\s\S]*?\*\//;
		if (existingHeaderRegex.test(out)) {
			out = out.replace(existingHeaderRegex, header.trim());
		} else {
			out = header + '\n' + out;
		}
	} else {
		// For JS files, update buildHash property and header
		const buildHashRegex = /buildHash:\s*['"][a-f0-9]*['"]/;
		if (buildHashRegex.test(out)) {
			out = out.replace(buildHashRegex, `buildHash: '${sha1}'`);
		}
		
		// Update or add header
		const existingHeaderRegex = new RegExp(`${HEADER_TAG}[\\s\\S]*?(?=\\n(?!\\s*\/\/))`); 
		if (out.includes(HEADER_TAG)) {
			out = out.replace(existingHeaderRegex, header.trim());
		} else {
			out = header + '\n' + out;
		}
	}
	
	return out;
}

function validateFile(filePath) {
	const resolvedPath = resolve(filePath);
	
	if (!existsSync(resolvedPath)) {
		throw new Error(`File not found: ${resolvedPath}`);
	}
	
	const ext = extname(resolvedPath).toLowerCase();
	if (!SUPPORTED_EXTENSIONS.includes(ext)) {
		throw new Error(`Unsupported file type: ${ext}. Supported: ${SUPPORTED_EXTENSIONS.join(', ')}`);
	}
	
	const stats = statSync(resolvedPath);
	if (stats.size > 10 * 1024 * 1024) { // 10MB limit
		throw new Error(`File too large: ${(stats.size / 1024 / 1024).toFixed(1)}MB (max 10MB)`);
	}
	
	return resolvedPath;
}

/**
 * Processes a single file: reads, hashes, updates, and writes back.
 * @param {string} filePath - Path to the file to process
 * @returns {boolean} Success status
 */
function updateChangelog(processedFiles) {
	const changelogPath = 'CHANGELOG.md';
	if (!existsSync(changelogPath)) return;
	
	try {
		const changelog = readFileSync(changelogPath, 'utf8');
		const timestamp = new Date().toISOString().split('T')[0];
		const buildEntry = `\n### Build ${timestamp}\n- Updated build hashes for: ${processedFiles.map(f => basename(f)).join(', ')}\n`;
		
		// Insert after first heading
		const updated = changelog.replace(
			/(# .+\n)/,
			`$1${buildEntry}`
		);
		
		if (updated !== changelog) {
			writeFileSync(changelogPath, updated, 'utf8');
			console.log('📝 Updated CHANGELOG.md');
		}
	} catch (error) {
		console.warn('⚠️  Could not update changelog:', error.message);
	}
}

function processFile(filePath) {
	try {
		const resolvedPath = validateFile(filePath);
		console.log(`📦 Processing: ${basename(resolvedPath)}`);
		
		const original = readFileSync(resolvedPath, 'utf8');
		const { sha1, sha256 } = hash(original);
		const updated = applyHeaderAndProp(original, sha1, sha256, resolvedPath);
		
		// Always write to ensure headers are current
		writeFileSync(resolvedPath, updated, 'utf8');
		
		if (original !== updated) {
			console.log(`✅ Updated with SHA-1: ${sha1.substring(0, 8)}...`);
			return { path: resolvedPath, updated: true, hash: sha1 };
		} else {
			console.log(`✅ Verified SHA-1: ${sha1.substring(0, 8)}...`);
			return { path: resolvedPath, updated: false, hash: sha1 };
		}
	} catch (error) {
		console.error(`❌ Error processing ${filePath}:`, error.message);
		return null;
	}
}

// --- Main Execution ---

function main() {
	const args = process.argv.slice(2);
	
	if (args.length === 0) {
		console.error('❌ No file path provided');
		console.error('Usage: node build-starmus.js <file-path> [file-path2] ...');
		console.error('Example: node build-starmus.js assets/js/starmus-audio-recorder-module.js');
		process.exit(1);
	}
	
	console.log(`🚀 Starmus Build Script - Processing ${args.length} file(s)`);
	
	const results = [];
	let allSucceeded = true;
	
	for (const filePath of args) {
		const result = processFile(filePath);
		if (result) {
			results.push(result);
		} else {
			allSucceeded = false;
		}
	}
	
	if (allSucceeded) {
		const updatedFiles = results.filter(r => r.updated).map(r => r.path);
		if (updatedFiles.length > 0) {
			updateChangelog(updatedFiles);
		}
		console.log('🎉 All files processed successfully!');
		process.exit(0);
	} else {
		console.error('💥 Some files failed to process');
		process.exit(1);
	}
}

// Handle uncaught errors gracefully
process.on('uncaughtException', (error) => {
	console.error('💥 Uncaught exception:', error.message);
	process.exit(1);
});

process.on('unhandledRejection', (reason) => {
	console.error('💥 Unhandled rejection:', reason);
	process.exit(1);
});

main();
