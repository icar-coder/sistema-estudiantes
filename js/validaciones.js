// Validaciones del formulario de login
document.addEventListener('DOMContentLoaded', function() {
    const loginForm = document.querySelector('form');
    
    if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
            const username = document.getElementById('username');
            const password = document.getElementById('password');
            let isValid = true;
            
            // Validar usuario
            if (username.value.trim() === '') {
                showError(username, 'Por favor ingrese su usuario');
                isValid = false;
            } else {
                clearError(username);
            }
            
            // Validar contraseña
            if (password.value.trim() === '') {
                showError(password, 'Por favor ingrese su contraseña');
                isValid = false;
            } else {
                clearError(password);
            }
            
            if (!isValid) {
                e.preventDefault();
            }
        });
    }
});

function showError(input, message) {
    const formGroup = input.closest('.mb-3');
    let errorDiv = formGroup.querySelector('.invalid-feedback');
    
    if (!errorDiv) {
        errorDiv = document.createElement('div');
        errorDiv.className = 'invalid-feedback';
        formGroup.appendChild(errorDiv);
    }
    
    errorDiv.textContent = message;
    input.classList.add('is-invalid');
}

function clearError(input) {
    input.classList.remove('is-invalid');
}