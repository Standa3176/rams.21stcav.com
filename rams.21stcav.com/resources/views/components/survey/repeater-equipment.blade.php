{{--
    survey/repeater-equipment

    Dynamic equipment repeater. Manages a list of structured items
    (type / status / location) stored in currentRoom.equipment[].

    Requires the parent Alpine.js surveyWizard() component to expose:
      - addEquipment()
      - removeEquipment(idx)
      - currentRoom.equipment  (array)
--}}

<div class="bg-white rounded-2xl p-4 shadow-sm">

    {{-- Header --}}
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-bold text-gray-900">Equipment Items</h3>
        <button type="button"
                @click="addEquipment()"
                class="flex items-center gap-1 px-3 py-2 bg-[#178A95] text-white
                       rounded-xl text-sm font-semibold min-h-[44px] hover:bg-[#0d6e77]
                       transition-colors">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            Add
        </button>
    </div>

    {{-- Empty state --}}
    <p x-show="!currentRoom?.equipment?.length"
       class="text-sm text-gray-400 text-center py-6">
        No equipment added yet — tap Add to start.
    </p>

    {{-- Item list --}}
    <div class="space-y-3">
        <template x-for="(item, idx) in (currentRoom?.equipment ?? [])" :key="idx">
            <div class="border border-gray-200 rounded-xl p-3 space-y-2.5">

                {{-- Item header --}}
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-gray-400 uppercase tracking-wide"
                          x-text="'Item ' + (idx + 1)"></span>
                    <button type="button"
                            @click="removeEquipment(idx)"
                            class="text-red-400 hover:text-red-600 p-1 rounded-lg
                                   transition-colors min-h-[44px] min-w-[44px] flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                {{-- Type --}}
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Type</label>
                    <select x-model="item.type"
                            class="w-full border border-gray-300 rounded-xl px-3 py-3 text-sm
                                   bg-white focus:outline-none focus:ring-2 focus:ring-[#178A95]
                                   min-h-[44px]">
                        <option value="">Select type…</option>
                        <option value="display">Display / Screen</option>
                        <option value="projector">Projector</option>
                        <option value="camera">Camera / PTZ</option>
                        <option value="mic">Microphone</option>
                        <option value="dsp">DSP / Amplifier</option>
                        <option value="vc">Video Conferencing Unit</option>
                        <option value="control">Control System</option>
                        <option value="switcher">AV Switcher / Matrix</option>
                        <option value="speaker">Speaker</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                {{-- Status + Location --}}
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
                        <select x-model="item.status"
                                class="w-full border border-gray-300 rounded-xl px-2 py-3 text-sm
                                       bg-white focus:outline-none focus:ring-2 focus:ring-[#178A95]
                                       min-h-[44px]">
                            <option value="new">New supply</option>
                            <option value="existing">Existing / reuse</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Location</label>
                        <select x-model="item.location"
                                class="w-full border border-gray-300 rounded-xl px-2 py-3 text-sm
                                       bg-white focus:outline-none focus:ring-2 focus:ring-[#178A95]
                                       min-h-[44px]">
                            <option value="">Select…</option>
                            <option value="front_wall">Front wall</option>
                            <option value="side_wall">Side wall</option>
                            <option value="table">Table / desk</option>
                            <option value="ceiling">Ceiling</option>
                            <option value="rack">Equipment rack</option>
                            <option value="floor">Floor</option>
                        </select>
                    </div>
                </div>

            </div>
        </template>
    </div>

</div>
