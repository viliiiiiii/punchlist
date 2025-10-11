import React from 'react';
import { Calendar, Copy, Edit2, MoveLeft, MoveRight, Trash2, User } from 'lucide-react';
import { useTasks } from '../context/TaskContext.jsx';
import { createId } from '../utils/id.js';
import {
  formatAssignee,
  formatDescription,
  formatDueDate,
  formatStatusLabel,
  formatTitle,
  sanitizeBuilding,
  sanitizeRoom,
  sanitizeSection,
  sanitizeSeverity,
  sanitizeStatus,
} from '../utils/sanitize.js';

const statusOrder = ['open', 'in_progress', 'done'];

export default function TaskCard({ task, onEdit, onPhotoPreview, compact = false }) {
  const { deleteTask, updateTask, duplicateTask } = useTasks();

  const currentStatus = sanitizeStatus(task.status);
  const statusLabel = formatStatusLabel(task.status);
  const severity = sanitizeSeverity(task.severity);
  const building = sanitizeBuilding(task.building);
  const room = sanitizeRoom(task.room);
  const section = sanitizeSection(task.section);
  const title = formatTitle(task.title);
  const description = formatDescription(task.description);
  const assignee = formatAssignee(task.assignee);
  const dueDate = formatDueDate(task.dueDate);

  const move = (direction) => {
    const index = statusOrder.indexOf(currentStatus);
    if (index === -1) return;
    const nextIndex = index + direction;
    if (nextIndex < 0 || nextIndex >= statusOrder.length) return;
    updateTask({ ...task, status: statusOrder[nextIndex], updatedAt: new Date().toISOString() });
  };

  const handleDuplicate = () => {
    const clone = {
      ...task,
      photos: task.photos ? task.photos.map((photo) => ({ ...photo })) : [],
      id: createId(),
      title: `${task.title} (Copy)`,
      status: 'open',
      createdAt: new Date().toISOString(),
      updatedAt: new Date().toISOString(),
    };
    duplicateTask(clone);
  };

  return (
    <div className={compact ? 'task-card compact' : 'task-card'}>
      <div className="task-header">
        <div>
          <h4>{title}</h4>
          <p className="task-meta">{building} • Room {room} • {section}</p>
        </div>
        <span className={`status-pill ${currentStatus}`}>{statusLabel}</span>
      </div>
      <p className="task-description">{description}</p>
      <div className="task-details">
        <span className={`severity ${severity}`}>{severity}</span>
        <span className="assignee"><User size={14} /> {assignee}</span>
        <span className="due"><Calendar size={14} /> {dueDate}</span>
      </div>
      {task.photos?.length > 0 && (
        <div className="photo-strip">
          {task.photos.map((photo, idx) => {
            const source = photo.thumb || photo.url;
            if (!source) return null;
            return (
              <button
                key={idx}
                type="button"
                className="photo-thumb"
                onClick={() => onPhotoPreview && onPhotoPreview(photo)}
              >
                <img src={source} alt="Task attachment" />
              </button>
            );
          })}
        </div>
      )}
      <div className="task-actions">
        <button className="ghost" onClick={() => onEdit(task)}>
          <Edit2 size={16} /> Edit
        </button>
        <button className="ghost" onClick={handleDuplicate}>
          <Copy size={16} /> Duplicate
        </button>
        <button className="ghost" onClick={() => deleteTask(task.id)}>
          <Trash2 size={16} /> Delete
        </button>
        <div className="spacer" />
        <button className="ghost" onClick={() => move(-1)} disabled={currentStatus === 'open'}>
          <MoveLeft size={16} />
        </button>
        <button className="ghost" onClick={() => move(1)} disabled={currentStatus === 'done'}>
          <MoveRight size={16} />
        </button>
      </div>
    </div>
  );
}
