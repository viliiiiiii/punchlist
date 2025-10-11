import React, { useMemo, useState } from 'react';
import { motion } from 'framer-motion';
import { Download, Plus } from 'lucide-react';
import TaskCard from './TaskCard.jsx';
import { useTasks } from '../context/TaskContext.jsx';
import { exportRoomTasks } from '../utils/pdf.js';

const sections = ['All', 'Bedroom', 'Bathroom', 'Balcony', 'Living', 'Entry', 'Other'];

export default function RoomsView({ onCreate, onEdit, buildingFilter, setBuildingFilter }) {
  const { tasks } = useTasks();
  const [sectionFilter, setSectionFilter] = useState('All');

  const buildings = useMemo(() => {
    const values = Array.from(new Set(tasks.map((task) => task.building))).sort();
    return ['all', ...values];
  }, [tasks]);

  const rooms = useMemo(() => {
    const filtered = tasks.filter((task) => {
      const matchesBuilding = buildingFilter === 'all' || task.building === buildingFilter;
      const matchesSection = sectionFilter === 'All' || task.section === sectionFilter;
      return matchesBuilding && matchesSection;
    });

    const grouped = new Map();
    filtered.forEach((task) => {
      const key = `${task.building}-${task.room}`;
      if (!grouped.has(key)) {
        grouped.set(key, {
          building: task.building,
          room: task.room,
          tasks: [],
        });
      }
      grouped.get(key).tasks.push(task);
    });

    return Array.from(grouped.values()).sort((a, b) => {
      if (a.building === b.building) {
        return a.room.localeCompare(b.room, undefined, { numeric: true });
      }
      return a.building.localeCompare(b.building);
    });
  }, [tasks, buildingFilter, sectionFilter]);

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
        <div className="filter-actions">
          <button className="ghost" onClick={onCreate}>
            <Plus size={16} /> New Task
          </button>
        </div>
      </div>
      <div className="room-grid">
        {rooms.length === 0 ? (
          <div className="empty">No rooms match the current filters.</div>
        ) : (
          rooms.map((room) => (
            <motion.div
              key={`${room.building}-${room.room}`}
              initial={{ opacity: 0, y: 6 }}
              animate={{ opacity: 1, y: 0 }}
              className="room-card"
            >
              <div className="room-header">
                <div>
                  <h3>Building {room.building}</h3>
                  <p className="room-meta">Room {room.room} • {room.tasks.length} tasks</p>
                </div>
                <button className="ghost" onClick={() => exportRoomTasks(room.building, room.room, room.tasks)}>
                  <Download size={16} /> Export Room
                </button>
              </div>
              <div className="room-tasks">
                {room.tasks.map((task) => (
                  <TaskCard key={task.id} task={task} onEdit={onEdit} compact />
                ))}
              </div>
            </motion.div>
          ))
        )}
      </div>
    </div>
  );
}
