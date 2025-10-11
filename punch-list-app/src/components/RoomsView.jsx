import React, { useMemo, useState } from 'react';
import { Download } from 'lucide-react';
import TaskCard from './TaskCard.jsx';
import { useTasks } from '../context/TaskContext.jsx';
import { exportRoomTasks } from '../utils/pdf.js';

export default function RoomsView({ onEdit, onPhotoPreview }) {
  const { tasks } = useTasks();
  const [buildingFilter, setBuildingFilter] = useState('All');
  const [activeRoomKey, setActiveRoomKey] = useState(null);

  const rooms = useMemo(() => {
    const filtered = buildingFilter === 'All' ? tasks : tasks.filter((task) => task.building === buildingFilter);
    const grouped = new Map();

    filtered.forEach((task) => {
      const key = `${task.building}-${task.room}`;
      if (!grouped.has(key)) {
        grouped.set(key, {
          key,
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
  }, [tasks, buildingFilter]);

  const buildings = useMemo(() => {
    const values = Array.from(new Set(tasks.map((task) => task.building))).sort();
    return ['All', ...values];
  }, [tasks]);

  const activeRoom = rooms.find((room) => room.key === activeRoomKey);

  return (
    <div className="panel rooms-directory">
      <div className="filters">
        <div className="filter-group">
          <label>Building</label>
          <select value={buildingFilter} onChange={(e) => {
            setBuildingFilter(e.target.value);
            setActiveRoomKey(null);
          }}>
            {buildings.map((value) => (
              <option key={value} value={value}>
                {value === 'All' ? 'All Buildings' : `Building ${value}`}
              </option>
            ))}
          </select>
        </div>
      </div>

      {rooms.length === 0 ? (
        <div className="empty">No rooms have tasks yet.</div>
      ) : (
        <div className="rooms-layout">
          <div className="room-chip-list">
            {rooms.map((room) => (
              <button
                key={room.key}
                className={room.key === activeRoomKey ? 'room-chip active' : 'room-chip'}
                onClick={() => setActiveRoomKey(room.key === activeRoomKey ? null : room.key)}
              >
                <span className="chip-building">{room.building}</span>
                <span className="chip-room">{room.room}</span>
                <span className="chip-count">{room.tasks.length}</span>
              </button>
            ))}
          </div>

          <div className="room-task-panel">
            {activeRoom ? (
              <div className="room-task-wrapper">
                <div className="room-task-header">
                  <div>
                    <h3>Building {activeRoom.building}</h3>
                    <p>Room {activeRoom.room} • {activeRoom.tasks.length} tasks</p>
                  </div>
                  <button
                    className="ghost"
                    onClick={() => exportRoomTasks(activeRoom.building, activeRoom.room, activeRoom.tasks)}
                  >
                    <Download size={16} /> Export Room
                  </button>
                </div>
                <div className="room-task-list">
                  {activeRoom.tasks
                    .sort((a, b) => new Date(b.updatedAt) - new Date(a.updatedAt))
                    .map((task) => (
                      <TaskCard key={task.id} task={task} onEdit={onEdit} onPhotoPreview={onPhotoPreview} compact />
                    ))}
                </div>
              </div>
            ) : (
              <div className="empty">Select a room to see its tasks.</div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
