function openModal(modalId) {
    document.getElementById(modalId).classList.add('active');
    document.body.style.overflow = 'hidden';

    var dropdown = document.getElementById("loginDropdown");
    if (dropdown) {
        dropdown.classList.remove("show");
    }
    if(modalId === 'registerModal') {
        var step1 = document.getElementById('step1');
        var step2 = document.getElementById('step2');
        var indicator = document.getElementById('step-indicator');
        
        if (typeof toggleRegAddress === 'function') { toggleRegAddress(); }
        if(step1) step1.style.display = 'block';
        if(step2) step2.style.display = 'none';
        if(indicator) indicator.innerText = 'Step 1 of 2: Personal Information';
    }
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
    document.body.style.overflow = ''; 
}

function toggleDropdown() {
    var dropdown = document.getElementById("loginDropdown");
    if(dropdown) dropdown.classList.toggle("show");
}

window.onclick = function(event) {
    // A modal marked data-static (the registration form) ignores clicks on the dark backdrop,
    // so half-typed details are never lost by a stray click. Use the X / Back button instead.
    if (event.target.classList.contains('modal-overlay') && !event.target.hasAttribute('data-static')) {
        event.target.classList.remove('active');
        document.body.style.overflow = '';
    }
    if (!event.target.matches('.dropbtn')) {
        var dropdowns = document.getElementsByClassName("dropdown-content");
        for (var i = 0; i < dropdowns.length; i++) {
            if (dropdowns[i].classList.contains('show')) {
                dropdowns[i].classList.remove('show');
            }
        }
    }
}

function togglePassword(inputId) {
    var input = document.getElementById(inputId);
    if (input && input.type === "password") {
        input.type = "text";
    } else if(input) {
        input.type = "password";
    }
}

/** Step 1 of the registration form. Returns the list of problems (empty = fine). */
function mhRegStep1Errors() {
    var $ = function (id) { return document.getElementById(id); };
    var errs = [];
    function need(el, label, msg) { if (el) { errs.push({ el: el, label: label, msg: msg }); } }

    var f = $('fname'), l = $('lname'), b = $('bdate'), m = $('mobile'), ph = $('philhealth');
    var sex = document.querySelector('#regForm input[name="sex"]');
    if (!f.value.trim()) { need(f, 'First Name', 'is required.'); }
    if (!l.value.trim()) { need(l, 'Last Name', 'is required.'); }
    if (!b.value) { need(b, 'Birthdate', 'is required.'); }
    else { var bp = window.mhBirthdateProblem ? window.mhBirthdateProblem(b.value) : ''; if (bp) { need(b, 'Birthdate', bp); } }
    if (!document.querySelector('#regForm input[name="sex"]:checked')) { need(sex, 'Biological Sex', 'please choose Male or Female.'); }
    if (!/^\+63[0-9]{10}$/.test(m.value.trim())) { need(m, 'Contact Number', 'must be +63 followed by 10 digits (e.g. +639123456789).'); }
    if (ph && ph.value !== '' && !/^[0-9]{12}$/.test(ph.value)) { need(ph, 'PhilHealth No.', 'must be exactly 12 digits (numbers only), or leave it blank.'); }
    return errs;
}

/** Step 2 (account + location). */
function mhRegStep2Errors() {
    var $ = function (id) { return document.getElementById(id); };
    var form = $('regForm'), errs = [];
    function need(el, label, msg) { if (el) { errs.push({ el: el, label: label, msg: msg }); } }

    var email = form.querySelector('input[name="email"]'), user = $('regUsername'), pw = $('regPwd'), cf = $('regConfirmPwd');
    var type = $('regAddressType');
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email.value.trim())) { need(email, 'Email Address', 'enter a valid email, e.g. name@example.com.'); }
    if (!/^[A-Za-z0-9._-]{4,50}$/.test(user.value.trim())) { need(user, 'Username', 'use 4-50 letters, numbers, dots, underscores or hyphens.'); }
    if (pw.value.length < 8) { need(pw, 'Create Password', 'must be at least 8 characters.'); }
    else if (pw.value.length > 72) { need(pw, 'Create Password', 'must be 72 characters or fewer.'); }
    if (cf.value === '') { need(cf, 'Confirm Password', 'please type your password again.'); }
    else if (cf.value !== pw.value) { need(cf, 'Confirm Password', 'does not match the password above.'); }
    if (!type.value) { need(type, 'Location Type', 'please choose one.'); }
    else {
        ['houseNumber', 'marickStreet', 'outsiderAddress'].forEach(function (id) {
            var el = $(id);
            if (el && !el.disabled && el.hasAttribute('required') && !String(el.value).trim()) {
                need(el, id === 'houseNumber' ? 'House/Blk No.' : (id === 'marickStreet' ? 'Street Name' : 'Full Address'), 'is required.');
            }
        });
    }
    return errs;
}

async function nextStep() {
    var form = document.getElementById('step1');
    window.mhClearErrors && window.mhClearErrors(form);
    var errs = mhRegStep1Errors();
    if (errs.length) { await window.mhReport(errs, 'Please check your personal information'); return; }

    document.getElementById('step1').style.display = 'none';
    document.getElementById('step2').style.display = 'block';

    let indicator = document.getElementById('step-indicator');
    if(indicator) indicator.innerText = 'Step 2 of 2: Account & Location Details';
}

function prevStep() {
    document.getElementById('step2').style.display = 'none';
    document.getElementById('step1').style.display = 'block';
    
    let indicator = document.getElementById('step-indicator');
    if(indicator) indicator.innerText = 'Step 1 of 2: Personal Information';
}

function validatePasswords() {
    var pwd = document.getElementById('regPwd').value;
    var confirmPwd = document.getElementById('regConfirmPwd').value;
    return pwd === confirmPwd;
}

document.addEventListener('DOMContentLoaded', function () {
    var regForm = document.getElementById('regForm');
    if (!regForm) { return; }
    regForm.addEventListener('submit', async function (e) {
        if (this.dataset.mhBypass === '1') { return; } // already validated, let it through
        e.preventDefault();
        const submitter = e.submitter;

        // Pressing Enter while still on step 1 must not submit a half-filled form.
        if (document.getElementById('step1').style.display !== 'none') { await nextStep(); return; }

        window.mhClearErrors && window.mhClearErrors(document.getElementById('step2'));
        var errs = mhRegStep2Errors();
        if (errs.length) { await window.mhReport(errs, 'Please check your account details'); return; }

        this.dataset.mhBypass = '1';
        // requestSubmit(submitter) preserves the button's name="register_patient" in the POST data,
        // unlike form.submit() which would drop it.
        this.requestSubmit(submitter);
    });
});

/** X button on the registration modal: ask first if something was typed, because the backdrop no longer closes it. */
async function closeRegisterModal() {
    var form = document.getElementById('regForm');
    var dirty = false;
    if (form) {
        Array.prototype.forEach.call(form.querySelectorAll('input:not([type=hidden]):not([type=radio]):not([type=checkbox]), select'), function (el) {
            var v = String(el.value || '').trim();
            if (v !== '' && v !== '+63' && !(el.tagName === 'SELECT' && el.selectedIndex === 0)) { dirty = true; }
        });
        if (form.querySelector('input[type=radio]:checked')) { dirty = true; }
    }
    if (dirty && window.mh_confirm) {
        var ok = await window.mh_confirm('What you typed will be cleared.', 'Close the registration form?', 'warning', 'Yes, close it');
        if (!ok) { return; }
    }
    if (form) { form.reset(); form.dataset.mhBypass = ''; window.mhClearErrors && window.mhClearErrors(form); if (typeof toggleRegAddress === 'function') { toggleRegAddress(); } }
    closeModal('registerModal');
}

/**
 * Which Location Type means "resident" is a System Setting (Home Address Type), not a literal:
 * the server puts it on the <select> as data-home-type (see mh_home_type_attr() in settings_helper.php).
 */
function mhIsHomeType(selectEl, value) {
    var home = (selectEl && selectEl.dataset && selectEl.dataset.homeType) || window.MH_HOME_ADDRESS_TYPE || '';
    return home.trim() !== '' && String(value).trim().toLowerCase() === home.trim().toLowerCase();
}

function toggleRegAddress() {
    var typeSelect = document.getElementById('regAddressType');
    if (!typeSelect) { return; }
    var chosen  = typeSelect.value !== '';
    var isHome  = chosen && mhIsHomeType(typeSelect, typeSelect.value);   // resident: house no. + street dropdown
    var isOther = chosen && !isHome;                                      // Outsider / any other type: free text

    function setup(groupId, fieldId, active, group) {
        var g = document.getElementById(groupId), f = document.getElementById(fieldId);
        if (g) { g.style.display = active ? 'block' : 'none'; }
        if (f) {
            // Hidden fields are DISABLED, not just un-required: disabled controls are skipped by
            // browser validation ("Please select an item in the list") and are not submitted.
            f.disabled = !active;
            if (active) { f.setAttribute('required', 'required'); } else { f.removeAttribute('required'); }
        }
        return f;
    }
    setup('marickHouseGroup', 'houseNumber', isHome);
    var street = setup('marickStreetGroup', 'marickStreet', isHome);
    if (street && !isHome) { street.selectedIndex = 0; }
    setup('outsiderAddressGroup', 'outsiderAddress', isOther);
}
document.addEventListener('DOMContentLoaded', toggleRegAddress);   // also fixes browser-restored form values
window.addEventListener('pageshow', toggleRegAddress);

function toggleForm(type) {
    if (!document.getElementById('btn-existing')) return; 

    document.getElementById('btn-existing').classList.remove('active');
    document.getElementById('btn-new').classList.remove('active');
    document.getElementById('btn-' + type).classList.add('active');

    document.getElementById('form-existing').classList.remove('active');
    document.getElementById('form-new').classList.remove('active');
    document.getElementById('form-' + type).classList.add('active');

    document.getElementById('patientTypeInput').value = type;

    var isNew = (type === 'new');
    document.getElementById('req_fname').required = isNew;
    document.getElementById('req_lname').required = isNew;
    document.getElementById('req_bdate').required = isNew;
    document.getElementById('req_sex').required = isNew;
    document.getElementById('req_contact').required = isNew;
    
    if(isNew) toggleAddressFields();
    else {
        document.getElementById('req_house').required = false;
        document.getElementById('req_street').required = false;
        document.getElementById('req_out_address').required = false;
    }
}

function toggleAddressFields() {
    var reqAddressType = document.getElementById('req_address_type');
    if (!reqAddressType) return;

    var type = reqAddressType.value;
    var isHome = mhIsHomeType(reqAddressType, type);
    var hNum = document.getElementById('req_house');
    var st = document.getElementById('req_street');
    var outAddr = document.getElementById('req_out_address');
    
    if(isHome) {
        document.getElementById('marickStreetGroup').style.display = 'block';
        document.getElementById('marickHouseGroup').style.display = 'block';
        document.getElementById('outsiderAddressGroup').style.display = 'none';
        
        hNum.required = true;
        st.required = true;
        outAddr.required = false;
    } else {
        document.getElementById('marickStreetGroup').style.display = 'none';
        document.getElementById('marickHouseGroup').style.display = 'none';
        document.getElementById('outsiderAddressGroup').style.display = 'block';
        
        outAddr.required = true;
        hNum.required = false;
        st.required = false;
    }
}
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('patientTypeInput')) {
        toggleForm('existing');
    }
});
if (typeof jQuery !== 'undefined') {
    $(document).ready(function() {
        if ($('#patientSelect').length) {
            if (typeof mhSelect2 === 'function') {
                mhSelect2('#patientSelect', { placeholder: 'Search masterlist...' });
            } else {
                $('#patientSelect').select2({ placeholder: 'Search masterlist...', allowClear: true });
            }
        }
    });
}