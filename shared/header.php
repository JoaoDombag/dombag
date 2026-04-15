

/* ── Topbar ── */
.topbar {
  padding: 14px 24px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--blue-mid);
  flex-shrink: 0;
  gap: 12px;
}

.topbar-left {
  display: flex;
  align-items: center;
  gap: 14px;
  min-width: 0;
}

.toggle-btn {
  width: 36px;
  height: 36px;
  min-width: 36px;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: transparent;
  color: var(--text-muted);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s, color 0.15s;
  flex-shrink: 0;
}

.toggle-btn:hover {
  background: var(--card-hover);
  color: var(--text-primary);
}

.page-title h1 {
  font-size: 17px;
  font-weight: 600;
  letter-spacing: -0.2px;
  white-space: nowrap;
}

.page-title p {
  font-size: 11.5px;
  color: var(--text-muted);
  margin-top: 1px;
  white-space: nowrap;
}

.topbar-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}

.btn-primary {
  background: var(--blue-accent);
  color: white;
  border: none;
  padding: 8px 16px;
  border-radius: 7px;
  font-size: 13px;
  font-weight: 600;
  font-family: 'Segoe UI', sans-serif;
  cursor: pointer;
  transition: background 0.15s;
  display: flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
}

.btn-primary:hover { background: var(--blue-light); }

.icon-btn {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: transparent;
  color: var(--text-muted);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s, color 0.15s;
  position: relative;
  flex-shrink: 0;
}

.icon-btn:hover { background: var(--card-hover); color: var(--text-primary); }

.badge-dot::after {
  content: '';
  position: absolute;
  top: 7px; right: 7px;
  width: 7px; height: 7px;
  background: var(--teal);
  border-radius: 50%;
  border: 2px solid var(--blue-mid);
}


