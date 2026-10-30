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
    if (event.target.classList.contains('modal-overlay')) {
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

async function nextStep() {
    let f = document.getElementById('fname').value;
    let l = document.getElementById('lname').value;
    let b = document.getElementById('bdate').value;
    let s = document.querySelector('input[name="sex"]:checked');
    let m = document.getElementById('mobile').value;
    
    if(!f || !l || !b || !s || !m) {
        await mh_alert("Please fill in all required fields (First Name, Last Name, Birthdate, Biological Sex, and Mobile Number).", "Missing Information", "warning");
        return;
    }
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
        if (this.dataset.mhBypass === '1') { return; } // already validated & confirmed, let it through
        e.preventDefault();
        const submitter = e.submitter;

        if (!validatePasswords()) {
            await mh_alert('Passwords do not match! Please type them carefully.', 'Password Mismatch', 'danger');
            return;
        }

        this.dataset.mhBypass = '1';
        // requestSubmit(submitter) preserves the button's name="register_patient" in the POST data,
        // unlike form.submit() which would drop it.
        this.requestSubmit(submitter);
    });
});

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