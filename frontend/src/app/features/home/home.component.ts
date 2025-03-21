import { Component, OnInit } from '@angular/core';
import { HttpClient } from '@angular/common/http';

@Component({
  selector: 'app-home',
  template: `
    <div class="container">
      <div class="row">
        <div class="col-md-12">
          <h1>Welcome to Angular PHP Project</h1>
          <p>This is a modern web application built with Angular and PHP.</p>
          
          <div class="card mt-4">
            <div class="card-body">
              <h5 class="card-title">API Status</h5>
              <p class="card-text">{{ apiStatus }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .card {
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
  `]
})
export class HomeComponent implements OnInit {
  apiStatus: string = 'Checking API status...';

  constructor(private http: HttpClient) {}

  ngOnInit() {
    this.checkApiStatus();
  }

  private checkApiStatus() {
    this.http.get('http://localhost:8000/')
      .subscribe({
        next: (response: any) => {
          this.apiStatus = response.message;
        },
        error: (error) => {
          this.apiStatus = 'Error connecting to API';
          console.error('API Error:', error);
        }
      });
  }
} 