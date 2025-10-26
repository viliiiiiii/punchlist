document.addEventListener('DOMContentLoaded', () => {
  /* =========================
     ROOMS (manual only + datalist)
     - No dropdown for room; user types a number
     - We still fetch rooms for the selected building to:
       a) populate datalist suggestions, and
       b) validate on submit (alert if not found)
     ========================= */
  const roomsCache = new Map(); // buildingId -> [{id,label}] where label starts with room_number
  const buildingSelect = document.querySelector('[data-room-source]');
  const roomInput      = buildingSelect ? document.getElementById(buildingSelect.dataset.roomInput) : null;
  const datalistEl     = buildingSelect ? document.getElementById(buildingSelect.dataset.roomDatalist) : null;

  async function fetchRooms(buildingId){
    if(!buildingId) return [];
    if(roomsCache.has(buildingId)) return roomsCache.get(buildingId);
    const res = await fetch(`rooms.php?action=by_building&id=${encodeURIComponent(buildingId)}`, { credentials:'same-origin' });
    if(!res.ok) throw new Error('Failed to load rooms');
    const data = await res.json(); // [{id,label: "006 - Kitchen"}]
    roomsCache.set(buildingId, data || []);
    return data || [];
  }

  function extractRoomNumber(label){
    // Your PHP returns: "room_number" or "room_number - Label"
    if (typeof label !== 'string') return '';
    const idx = label.indexOf(' - ');
    return (idx === -1 ? label : label.slice(0, idx)).trim();
  }

  function fillDatalist(rooms){
    if(!datalistEl) return;
    datalistEl.innerHTML = '';
    rooms.forEach(r => {
      const num = extractRoomNumber(r.label);
      if(!num) return;
      const o = document.createElement('option');
      o.value = num;
      datalistEl.appendChild(o);
    });
  }

  function validateManual(rooms){
    if(!roomInput) return true;
    const val = roomInput.value.trim();
    if(!val){ roomInput.setCustomValidity(''); return true; }
    const exists = rooms.some(r => extractRoomNumber(r.label).toLowerCase() === val.toLowerCase());
    if(!exists){
      roomInput.setCustomValidity('This room does not exist for the selected building.');
      return false;
    }
    roomInput.setCustomValidity('');
    return true;
  }

  if(buildingSelect){
    buildingSelect.addEventListener('change', async (event) => {
      const buildingId = event.target.value;
      if (!buildingId) {
        if (datalistEl) datalistEl.innerHTML = '';
        return;
      }
      try{
        const rooms = await fetchRooms(buildingId);
        fillDatalist(rooms);
      }catch(_){ /* ignore */ }
    });
  }

  // Validate on blur for quick feedback
  if(roomInput && buildingSelect){
    roomInput.addEventListener('blur', async () => {
      const id = buildingSelect.value;
      if(!id) return;
      try{
        const rooms = await fetchRooms(id);
        validateManual(rooms);
      }catch(_){}
    });
  }

  /* ======================================
     TASK CREATE FORM — client-side checks
     ====================================== */
  const createForm = document.querySelector('[data-create-task]');
  if(createForm && roomInput && buildingSelect){
    createForm.addEventListener('submit', async (e) => {
      const buildingId = buildingSelect.value;
      if(!buildingId){ return; } // native "required" will handle
      try{
        const rooms = await fetchRooms(buildingId);
        const ok = validateManual(rooms);
        if(!ok){
          e.preventDefault();
          alert('Room does not exist in the selected building.');
          roomInput.reportValidity();
          return false;
        }
      }catch(_){ /* if fetch fails, let server validate */ }
      return true;
    });
  }

  /* =========================================
     OPTIONAL: existing photo upload/delete JS
     (leave your other listeners intact if any)
     ========================================= */
});