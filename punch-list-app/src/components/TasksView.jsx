import React, { useMemo, useState } from 'react';
import { Plus, Download } from 'lucide-react';
import { useTasks } from '../context/TaskContext.jsx';
import { exportRoomTasks } from '../utils/pdf.js';

const sections = ['All', 'Bedroom', 'Bathroom', 'Balcony', 'Living', 'Entry', 'Other'];

export default function TasksView({ onCreate, onEdit, buildingFilter, setBuildingFilter }) {
  const { tasks } = useTasks();
  const [sectionFilter, setSectionFilter] = useState('All');
  const [statusFilter, setStatusFilter] = useState('All');
  const [search, setSearch] = useState('');

  const buildings = useMemo(() => {
    const values = Array.from(new Set(tasks.map((task) => task.building))).sort();
    return ['all', ...values];
  }, [tasks]);

  const filteredTasks = useMemo(() => {
    return tasks
      .filter((task) => {
        const matchesBuilding = buildingFilter === 'all' || task.building === buildingFilter;
        const matchesSection = sectionFilter === 'All' || task.section === sectionFilter;
        const matchesStatus = statusFilter === 'All' || task.status === statusFilter.toLowerCase();
        const query = search.trim().toLowerCase();
        const title = (task.title || '').toLowerCase();
        const room = (task.room || '').toLowerCase();
        const description = (task.description || '').toLowerCase();
        const matchesQuery =
          query.length === 0 ||
          title.includes(query) ||
          room.includes(query) ||
          description.includes(query);
        return matchesBuilding && matchesSection && matchesStatus && matchesQuery;
      })
      .sort((a, b) => new Date(b.updatedAt) - new Date(a.updatedAt));
  }, [tasks, buildingFilter, sectionFilter, statusFilter, search]);

  return (
    <div className="panel">
      <div className="filters">
        <div className="filter-group">
          <label>Building</label>
          <select value={buildingFilter} onChange={(e) => setBuildingFilter(e.target.value)}>
            {buildings.map((value) => (
              <option key={value} value={value}>
                {value === 'all' ? 'All Buildings' : `Building ${value}`}
              </option>
            ))}
          </select>
        </div>
        <div className="filter-group">
          <label>Section</label>
          <select value={sectionFilter} onChange={(e) => setSectionFilter(e.target.value)}>
            {sections.map((section) => (
              <option key={section} value={section}>
                {section}
              </option>
            ))}
          </select>
        </div>
        <div className="filter-group">
          <label>Status</label>
          <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
            {['All', 'Open', 'In Progress', 'Done'].map((option) => (
              <option key={option} value={option}>
                {option}
              </option>
            ))}
          </select>
        </div>
        <div className="filter-group search-group">
          <label>Search</label>
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Title, room, or description"
          />
        </div>
        <div className="filter-actions">
          <button className="ghost" onClick={onCreate}>
            <Plus size={16} /> New Task
          </button>
        </div>
      </div>

      <div className="table-scroll">
        <table className="task-table">
          <thead>
            <tr>
              <th>Task</th>
              <th>Building</th>
              <th>Room</th>
              <th>Section</th>
              <th>Status</th>
              <th>Severity</th>
              <th>Due</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {filteredTasks.length === 0 ? (
              <tr>
                <td colSpan={8} className="empty">
                  No tasks match the current filters.
                </td>
              </tr>
            ) : (
              filteredTasks.map((task) => (
                <tr key={task.id}>
                  <td>
                    <div className="table-primary">
                      <div className="table-title">{task.title}</div>
                      <div className="table-subtitle">{task.description || 'No description provided.'}</div>
                    </div>
                  </td>
                  <td>{task.building}</td>
                  <td>{task.room}</td>
                  <td>{task.section}</td>
                  <td className={`status-text ${task.status}`}>{task.status.replace('_', ' ')}</td>
                  <td className={`severity-text ${task.severity}`}>{task.severity}</td>
                  <td>{task.dueDate || '—'}</td>
                  <td>
                    <div className="table-actions">
                      <button className="ghost" onClick={() => onEdit(task)}>
                        Edit
                      </button>
                      <button
                        className="ghost"
                        onClick={() =>
                          exportRoomTasks(
                            task.building,
                            task.room,
                            tasks.filter((t) => t.room === task.room && t.building === task.building)
                          )
                        }
                      >
                        <Download size={14} /> Room PDF
                      </button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
