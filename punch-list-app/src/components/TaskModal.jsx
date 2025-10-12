import React, { useEffect, useState } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { Camera, ImagePlus, X } from 'lucide-react';
import { useTasks } from '../context/TaskContext.jsx';
import { createId } from '../utils/id.js';
import { postPresign } from '../utils/presign.js';

const sections = ['Bedroom', 'Bathroom', 'Balcony', 'Living', 'Entry', 'Other'];
const severities = ['low', 'medium', 'high'];

async function readFile(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = (error) => reject(error);
    reader.readAsDataURL(file);
  });
}

async function resize(dataUrl, max) {
  const image = new Image();
  image.src = dataUrl;
  await new Promise((resolve) => {
    image.onload = resolve;
    image.onerror = resolve;
  });
  if (!image.width || !image.height) {
    return dataUrl;
  }
  const scale = Math.min(1, max / Math.max(image.width, image.height));
  const width = Math.round(image.width * scale);
  const height = Math.round(image.height * scale);
  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext('2d');
  ctx.drawImage(image, 0, 0, width, height);
  return canvas.toDataURL('image/jpeg', 0.85);
}

async function dataUrlToBlob(dataUrl) {
  const response = await fetch(dataUrl);
  return response.blob();
}

export default function TaskModal({ open, onClose, task }) {
  const { addTask, updateTask, settings } = useTasks();
  const [form, setForm] = useState({
    title: '',
    building: '',
    room: '',
    section: sections[0],
    description: '',
    severity: 'medium',
    assignee: '',
    dueDate: '',
    status: 'open',
    photos: [],
  });
  const [saving, setSaving] = useState(false);
  const isEditing = Boolean(task);

  useEffect(() => {
    if (task) {
      setForm({ ...task, photos: task.photos || [] });
    } else {
      setForm({
        title: '',
        building: '',
        room: '',
        section: sections[0],
        description: '',
        severity: 'medium',
        assignee: '',
        dueDate: '',
        status: 'open',
        photos: [],
      });
    }
    setSaving(false);
  }, [task, open]);

  const handleChange = (event) => {
    const { name, value } = event.target;
    setForm((prev) => ({ ...prev, [name]: value }));
  };

  const handlePhotoChange = async (event) => {
    const files = Array.from(event.target.files || []);
    if (files.length === 0) return;
    setSaving(true);
    const processed = [];
    let remoteFailures = false;
    for (const file of files) {
      try {
        const raw = await readFile(file);
        const full = await resize(raw, 1200);
        const thumb = await resize(raw, 240);
        if (settings.presignEndpoint) {
          try {
            const blob = await dataUrlToBlob(full);
            const { url, key } = await postPresign(settings.presignEndpoint, 'upload', {
              contentType: 'image/jpeg',
              fileSize: blob.size,
            });
            const uploadResponse = await fetch(url, {
              method: 'PUT',
              headers: {
                'Content-Type': 'image/jpeg',
              },
              body: blob,
            });
            if (!uploadResponse.ok) {
              throw new Error('Upload failed');
            }
            processed.push({ key, url: null, thumb });
            continue;
          } catch (error) {
            console.error('Remote upload failed; falling back to local storage', error);
            remoteFailures = true;
          }
        }
        processed.push({ url: full, thumb, key: null });
      } catch (error) {
        console.error('Failed to process photo', error);
      }
    }
    setForm((prev) => ({ ...prev, photos: [...(prev.photos || []), ...processed] }));
    setSaving(false);
    event.target.value = '';
    if (remoteFailures && typeof window !== 'undefined') {
      window.alert('Some photos could not be uploaded. They were stored locally instead.');
    }
  };

  const removePhoto = (index) => {
    setForm((prev) => ({
      ...prev,
      photos: prev.photos.filter((_, idx) => idx !== index),
    }));
  };

  const handleSubmit = (event) => {
    event.preventDefault();
    setSaving(true);
    const timestamp = new Date().toISOString();
    if (isEditing) {
      updateTask({ ...form, updatedAt: timestamp });
    } else {
      addTask({
        ...form,
        id: createId(),
        createdAt: timestamp,
        updatedAt: timestamp,
      });
    }
    setSaving(false);
    onClose();
  };

  return (
    <AnimatePresence>
      {open && (
        <motion.div className="modal-overlay" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
          <motion.div
            className="modal"
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: 20 }}
          >
            <div className="modal-header">
              <h2>{isEditing ? 'Edit Task' : 'New Task'}</h2>
              <button className="ghost" onClick={onClose}>
                <X size={18} />
              </button>
            </div>
            <form className="modal-body" onSubmit={handleSubmit}>
              <div className="form-grid">
                <label>
                  <span>Title</span>
                  <input name="title" value={form.title} onChange={handleChange} required />
                </label>
                <label>
                  <span>Building</span>
                  <input name="building" value={form.building} onChange={handleChange} required />
                </label>
                <label>
                  <span>Room</span>
                  <input name="room" value={form.room} onChange={handleChange} required />
                </label>
                <label>
                  <span>Section</span>
                  <select name="section" value={form.section} onChange={handleChange}>
                    {sections.map((section) => (
                      <option key={section} value={section}>
                        {section}
                      </option>
                    ))}
                  </select>
                </label>
                <label>
                  <span>Severity</span>
                  <select name="severity" value={form.severity} onChange={handleChange}>
                    {severities.map((severity) => (
                      <option key={severity} value={severity}>
                        {severity}
                      </option>
                    ))}
                  </select>
                </label>
                <label>
                  <span>Status</span>
                  <select name="status" value={form.status} onChange={handleChange}>
                    <option value="open">Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="done">Done</option>
                  </select>
                </label>
                <label>
                  <span>Assignee</span>
                  <input name="assignee" value={form.assignee} onChange={handleChange} />
                </label>
                <label>
                  <span>Due Date</span>
                  <input type="date" name="dueDate" value={form.dueDate} onChange={handleChange} />
                </label>
              </div>
              <label className="full">
                <span>Description</span>
                <textarea name="description" value={form.description} onChange={handleChange} rows={4} />
              </label>
              <div className="photo-uploader">
                <p>Photos ({form.photos?.length || 0})</p>
                <div className="photo-actions">
                  <label className="ghost">
                    <Camera size={16} /> Capture
                    <input type="file" accept="image/*" capture="environment" onChange={handlePhotoChange} hidden />
                  </label>
                  <label className="ghost">
                    <ImagePlus size={16} /> Upload
                    <input type="file" accept="image/*" multiple onChange={handlePhotoChange} hidden />
                  </label>
                </div>
                {settings.presignEndpoint && (
                  <p className="hint">Presign endpoint configured: {settings.presignEndpoint}</p>
                )}
                <div className="photo-grid">
                  {form.photos?.map((photo, index) => (
                    <div key={index} className="photo-item">
                      <img src={photo.thumb || photo.url} alt="task" />
                      <button type="button" className="ghost" onClick={() => removePhoto(index)}>
                        Remove
                      </button>
                    </div>
                  ))}
                </div>
              </div>
              <div className="modal-footer">
                <button type="button" className="ghost" onClick={onClose}>
                  Cancel
                </button>
                <button className="primary" type="submit" disabled={saving}>
                  {saving ? 'Saving…' : 'Save Task'}
                </button>
              </div>
            </form>
          </motion.div>
        </motion.div>
      )}
    </AnimatePresence>
  );
}
