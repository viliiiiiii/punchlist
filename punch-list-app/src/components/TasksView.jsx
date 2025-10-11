import React, { useMemo, useState } from 'react';
import { Plus, Download } from 'lucide-react';
import { useTasks } from '../context/TaskContext.jsx';
import { exportRoomTasks } from '../utils/pdf.js';
import {
  formatDescription,
  formatDueDateShort,
  formatStatusLabel,
  formatTitle,
  sanitizeBuilding,
  sanitizeRoom,
  sanitizeSection,
  sanitizeSeverity,
  sanitizeStatus,
  sanitizeText,
} from '../utils/sanitize.js';

const sections = ['All', 'Bedroom', 'Bathroom', 'Balcony', 'Living', 'Entry', 'Other'];

export default function TasksView({ onCreate, onEdit, buildingFilter, setBuildingFilter }) {
  const { tasks } = useTasks();
  const [sectionFilter, setSectionFilter] = useState('All');
  const [statusFilter, setStatusFilter] = useState('All');
  const [search, setSearch] = useState('');

  const buildings = useMemo(() => {
    const values = Array.from(new Set(tasks.map((task) => sanitizeBuilding(task.building))))
      .sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
    return ['all', ...values];
  }, [tasks]);

  const filteredTasks = useMemo(() => {
    const statusLookup = {
      All: null,
      Open: 'open',
      'In Progress': 'in_progress',
      Done: 'done',
    };
    const statusFilterValue = statusLookup[statusFilter];
    return tasks
      .filter((task) => {
        const buildingValue = sanitizeBuilding(task.building);
        const sectionValue = sanitizeSection(task.section);
        const statusValue = sanitizeStatus(task.status);
        const matchesBuilding = buildingFilter === 'all' || buildingValue === buildingFilter;
        const matchesSection = sectionFilter === 'All' || sectionValue === sectionFilter;
        const matchesStatus = !statusFilterValue || statusValue === statusFilterValue;
        const query = search.trim().toLowerCase();
        const title = sanitizeText(task.title).toLowerCase();
        const room = sanitizeText(task.room).toLowerCase();
        const description = sanitizeText(task.description).toLowerCase();
        const matchesQuery =
          query.length === 0 ||
          title.includes(query) ||
          room.includes(query) ||
          description.includes(query);
        return matchesBuilding && matchesSection && matchesStatus && matchesQuery;
      })
      .sort((a, b) => new Date(b.updatedAt || b.createdAt || 0) - new Date(a.updatedAt || a.createdAt || 0));
  }, [tasks, buildingFilter, sectionFilter, statusFilter, search]);

  const renderBuildingLabel = (value) => {
    if (value === 'all') return 'All Buildings';
    if (value === 'Unassigned') return 'Unassigned Building';
    return `Building ${value}`;
  };

  return (
    <div className="panel">
      <div className="filters">
        <div className="filter-group">
          <label>Building</label>
          <select value={buildingFilter} onChange={(e) => setBuildingFilter(e.target.value)}>
            {buildings.map((value) => (
              <option key={value} value={value}>
                {renderBuildingLabel(value)}
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
                      <div className="table-title">{formatTitle(task.title)}</div>
                      <div className="table-subtitle">{formatDescription(task.description)}</div>
                    </div>
                  </td>
                  <td>{sanitizeBuilding(task.building)}</td>
                  <td>{sanitizeRoom(task.room)}</td>
                  <td>{sanitizeSection(task.section)}</td>
                  <td className={`status-text ${sanitizeStatus(task.status)}`}>{formatStatusLabel(task.status)}</td>
                  <td className={`severity-text ${sanitizeSeverity(task.severity)}`}>{sanitizeSeverity(task.severity)}</td>
                  <td>{formatDueDateShort(task.dueDate)}</td>
                  <td>
                    <div className="table-actions">
                      <button className="ghost" onClick={() => onEdit(task)}>
                        Edit
                      </button>
                      <button
                        className="ghost"
                        onClick={() =>
                          exportRoomTasks(
                            sanitizeBuilding(task.building),
                            sanitizeRoom(task.room),
                            tasks.filter(
                              (t) =>
                                sanitizeRoom(t.room) === sanitizeRoom(task.room) &&
                                sanitizeBuilding(t.building) === sanitizeBuilding(task.building)
                            )
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
