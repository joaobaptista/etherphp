/**
 * socketService.js — Lightweight client shim for Laravel monolith.
 * Notifications are synchronized via HTTP polling (/api/notifications) for full
 * shared-hosting compatibility. Can be upgraded to Laravel Reverb / Echo without
 * changing consumer components.
 */
class SocketService {
  constructor() {
    this.socket = null;
    this.isConnected = false;
    this._listeners = new Map();
  }

  connect() {
    this.isConnected = true;
    return this;
  }

  getSocket() {
    if (!this.isConnected) {
      return this.connect();
    }
    return this;
  }

  on(event, callback) {
    if (!this._listeners.has(event)) {
      this._listeners.set(event, new Set());
    }
    this._listeners.get(event).add(callback);
    return () => this.off(event, callback);
  }

  off(event, callback) {
    this._listeners.get(event)?.delete(callback);
  }

  emit(event, data, callback) {
    if (typeof callback === 'function') {
      callback({ ok: true, event, data });
    }
  }

  disconnect() {
    this._listeners.clear();
    this.isConnected = false;
    this.socket = null;
  }
}

export const socketService = new SocketService();
export default socketService;
