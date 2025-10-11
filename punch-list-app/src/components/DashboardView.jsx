import React, { useMemo } from 'react';
import { motion } from 'framer-motion';
import { useTasks } from '../context/TaskContext.jsx';

function buildCounts(items, keyFn) {
  const counts = new Map();
  items.forEach((item) => {
    const key = keyFn(item);
    counts.set(key, (counts.get(key) || 0) + 1);
  });
  return Array.from(counts.entries())
    .map(([name, count]) => ({ name, count }))
    .sort((a, b) => b.count - a.count)
    .slice(0, 5);
}

export default function DashboardView({ stats }) {
  const { tasks } = useTasks();

  const topSections = useMemo(() => buildCounts(tasks, (task) => task.section || 'Unassigned'), [tasks]);
  const topRooms = useMemo(() => buildCounts(tasks, (task) => `${task.building}-${task.room}`), [tasks]);
  const recent = useMemo(
    () => [...tasks].sort((a, b) => new Date(b.updatedAt) - new Date(a.updatedAt)).slice(0, 8),
    [tasks]
  );

  const kpis = [
    { label: 'Total Tasks', value: stats.total },
    { label: 'Open', value: stats.open },
    { label: 'In Progress', value: stats.in_progress },
    { label: 'Done', value: stats.done },
  ];

  const maxSectionCount = Math.max(1, ...topSections.map((item) => item.count));
  const maxRoomCount = Math.max(1, ...topRooms.map((item) => item.count));

  return (
    <div className="dashboard">
      <div className="kpi-row">
        {kpis.map((kpi) => (
          <motion.div key={kpi.label} className="kpi" initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }}>
            <span className="kpi-label">{kpi.label}</span>
            <strong className="kpi-value">{kpi.value}</strong>
          </motion.div>
        ))}
      </div>
      <div className="dashboard-grid">
        <div className="dashboard-card">
          <h3>Top Sections</h3>
          {topSections.length === 0 ? (
            <div className="empty">No data yet.</div>
          ) : (
            <div className="bar-list">
              {topSections.map((item) => (
                <div key={item.name} className="bar-item">
                  <span>{item.name}</span>
                  <div className="bar">
                    <div className="fill" style={{ width: `${(item.count / maxSectionCount) * 100}%` }} />
                  </div>
                  <span className="bar-value">{item.count}</span>
                </div>
              ))}
            </div>
          )}
        </div>
        <div className="dashboard-card">
          <h3>Top Rooms</h3>
          {topRooms.length === 0 ? (
            <div className="empty">No data yet.</div>
          ) : (
            <div className="bar-list">
              {topRooms.map((item) => (
                <div key={item.name} className="bar-item">
                  <span>{item.name}</span>
                  <div className="bar">
                    <div className="fill" style={{ width: `${(item.count / maxRoomCount) * 100}%` }} />
                  </div>
                  <span className="bar-value">{item.count}</span>
                </div>
              ))}
            </div>
          )}
        </div>
        <div className="dashboard-card wide">
          <h3>Recent Updates</h3>
          {recent.length === 0 ? (
            <div className="empty">No recent activity.</div>
          ) : (
            <ul className="recent-list">
              {recent.map((task) => (
                <li key={task.id}>
                  <div>
                    <strong>{task.title}</strong>
                    <p>{task.building} • Room {task.room} • {task.section}</p>
                  </div>
                  <span className={`status-pill ${task.status}`}>{task.status.replace('_', ' ')}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </div>
  );
}
