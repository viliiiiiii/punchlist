import React from 'react';
import { Calendar, Copy, Edit2, MoveLeft, MoveRight, Trash2, User } from 'lucide-react';
import { useTasks } from '../context/TaskContext.jsx';
import { createId } from '../utils/id.js';

const statusOrder = ['open', 'in_progress', 'done'];

export default function TaskCard({ task, onEdit, compact = false }) {
  const { deleteTask, updateTask, duplicateTask } = useTasks();

  const move = (direction) => {
    const index = statusOrder.indexOf(task.status);
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
          <h4>{task.title}</h4>
          <p className="task-meta">{task.building} • Room {task.room} • {task.section}</p>
        </div>
        <span className={`status-pill ${task.status}`}>{task.status.replace('_', ' ')}</span>
      </div>
      <p className="task-description">{task.description}</p>
      <div className="task-details">
        <span className={`severity ${task.severity}`}>{task.severity}</span>
        <span className="assignee"><User size={14} /> {task.assignee || 'Unassigned'}</span>
        <span className="due"><Calendar size={14} /> {task.dueDate || 'No due date'}</span>
      </div>
      {task.photos?.length > 0 && (
        <div className="photo-strip">
          {task.photos.map((photo, idx) => (
            <img key={idx} src={photo.thumb || photo.url} alt="task" />
          ))}
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
        <button className="ghost" onClick={() => move(-1)} disabled={task.status === 'open'}>
          <MoveLeft size={16} />
        </button>
        <button className="ghost" onClick={() => move(1)} disabled={task.status === 'done'}>
          <MoveRight size={16} />
        </button>
      </div>
    </div>
  );
}
