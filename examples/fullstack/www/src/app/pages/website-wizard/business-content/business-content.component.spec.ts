import { ComponentFixture, TestBed } from '@angular/core/testing';

import { BusinessContentComponent } from './business-content.component';

describe('BusinessContentComponent', () => {
  let component: BusinessContentComponent;
  let fixture: ComponentFixture<BusinessContentComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
    imports: [BusinessContentComponent]
})
    .compileComponents();

    fixture = TestBed.createComponent(BusinessContentComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
