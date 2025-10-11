import React, { useMemo } from 'react';
import { motion } from 'framer-motion';
import TaskCard from './TaskCard.jsx';
import { useTasks } from '../context/TaskContext.jsx';

const columns = [
  { id: 'open', label: 'Open' },
  { id: 'in_progress', label: 'In Progress' },
  { id: 'done', label: 'Done' },
];

export default function BoardView({ onEdit, onPhotoPreview }) {
  const { tasks } = useTasks();

  const grouped = useMemo(() => {
    return columns.map((column) => ({
      ...column,
      tasks: tasks.filter((task) => task.status === column.id).sort((a, b) => new Date(b.updatedAt) - new Date(a.updatedAt)),
    }));
  }, [tasks]);

  return (
    <div className="board">
      {grouped.map((column) => (
        <div key={column.id} className="board-column">
          <div className="board-header">
            <h3>{column.label}</h3>
            <span>{column.tasks.length}</span>
          </div>
          <div className="board-list">
            {column.tasks.length === 0 ? (
              <div className="empty">No tasks here yet.</div>
            ) : (
              column.tasks.map((task) => (
                <motion.div key={task.id} initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }}>
                  <TaskCard task={task} onEdit={onEdit} onPhotoPreview={onPhotoPreview} />
                </motion.div>
              ))
            )}
          </div>
        </div>
      ))}
    </div>
  );
}
